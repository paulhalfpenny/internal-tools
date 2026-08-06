# Livewire Navigation Prefetch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all primary internal navigation links use Livewire navigation with hover prefetch while retaining their normal `href` fallback.

**Architecture:** The shared application layout owns primary navigation, so navigation behavior remains declarative on its internal anchors. A focused feature test parses the rendered header for an administrator and verifies that every visible primary anchor carries the Livewire hover-navigation directive.

**Tech Stack:** Laravel 12, Livewire 4.2, Blade, Pest.

## Global Constraints

- Change only ordinary internal anchors in `resources/views/layouts/app.blade.php`.
- Preserve every existing `href`; do not alter dropdown-toggle buttons or the logout form.
- Use `wire:navigate.hover` exactly once on each enhanced anchor.
- Do not add custom JavaScript navigation listeners or Livewire lifecycle hooks.

---

### Task 1: Cover enhanced primary navigation

**Files:**
- Create: `tests/Feature/LivewireNavigationTest.php`
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: an authenticated administrator, `route('timesheet')`, and the shared application layout.
- Produces: rendered primary navigation links with the `wire:navigate.hover` HTML attribute.

- [ ] **Step 1: Write the failing test**

```php
test('every visible primary navigation link uses Livewire hover navigation', function () {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('timesheet'))
        ->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent());

    foreach ($document->getElementsByTagName('nav')->item(0)->getElementsByTagName('a') as $link) {
        expect($link->getAttribute('href'))->not->toBe('')
            ->and($link->hasAttribute('wire:navigate.hover'))->toBeTrue();
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/LivewireNavigationTest.php`

Expected: FAIL because header links do not yet expose `wire:navigate.hover`.

- [ ] **Step 3: Write minimal implementation**

```blade
<a href="{{ route('timesheet') }}" wire:navigate.hover>
```

Apply this attribute to every ordinary primary-navigation anchor in the shared layout. Leave buttons and forms unchanged.

- [ ] **Step 4: Run focused test to verify it passes**

Run: `php artisan test tests/Feature/LivewireNavigationTest.php`

Expected: PASS.

- [ ] **Step 5: Verify regressions**

Run: `php artisan test && npm run build`

Expected: all Laravel tests pass and Vite completes the production build.

- [ ] **Step 6: Commit**

Do not commit unless the user explicitly requests it.
