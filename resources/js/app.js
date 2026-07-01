import './bootstrap';
import './searchable-select';
import * as asanaTaskFilter from './asana-task-filter';

if (typeof window !== 'undefined') {
    window.asanaTaskFilter = asanaTaskFilter;
}
