# Report and Schedule Query Performance Record

## Data set and baseline

The supplied performance export contains 224,004 time entries and 36,767 unique
date/project combinations. The ticket baseline recorded:

| Query | Baseline |
| --- | ---: |
| Report totals aggregate | 26.4 ms |
| Grouped-project report aggregate | 137 ms |
| Schedule lifetime project actuals aggregate | 139 ms |
| `DATE(spent_on)` equality | 16.0 ms |
| Direct `spent_on` equality | 0.18 ms |

The local development database used for automated verification does not contain
that export, so this record deliberately does not claim local 224k-row timings.

## Implemented query changes

- Time Report renders rows and headline totals from one grouped aggregate query.
- Lifetime project actuals use a versioned 30-minute cache. Time-entry creation,
  deletion, project moves, and hours changes advance the version before the next
  lookup. Raw time entries remain the source of truth.
- DayView and schedule DATE columns use direct equality/range predicates. The
  schedule overlap queries have date-leading indexes on `(ends_on, starts_on)`.

## Production measurement procedure

Run against a production-sized copy of the data using MySQL 8:

```sql
EXPLAIN ANALYZE
SELECT project_id, SUM(hours) AS total_hours
FROM time_entries
WHERE project_id IN (...)
GROUP BY project_id;

EXPLAIN ANALYZE
SELECT *
FROM time_entries
WHERE user_id = ? AND spent_on = ?
ORDER BY created_at;

EXPLAIN ANALYZE
SELECT *
FROM schedule_assignments
WHERE ends_on >= ? AND starts_on <= ?;
```

For p50/p95, issue each representative Livewire request 100 times after one warm
request, record elapsed application time, sort the measurements, and read the
50th and 95th positions. Compare the report render with the prior two-scan
baseline and schedule navigation with a cold and warm lifetime-actual cache.

## Expected verification outcomes

- A grouped Time Report render performs one raw time-entry aggregation.
- A warm lifetime actuals lookup performs no `SUM(hours)` query.
- The DayView query uses the `(user_id, spent_on)` index without a DATE function
  around `spent_on`.
- Schedule overlap queries use direct `ends_on` and `starts_on` comparisons and
  retain inclusive range boundaries.
