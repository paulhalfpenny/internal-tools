# Livewire Navigation Prefetch Design

## Goal

Make primary desktop navigation feel immediate by having Livewire prefetch
ordinary internal header links on hover and navigate without a full-page reload.

## Scope

Add `wire:navigate.hover` to every internal anchor in `resources/views/layouts/app.blade.php`:

- The Filter logo and Track Time link.
- The Schedule link when it is visible.
- Report, admin, and profile-menu destination links when they are visible.

Do not change dropdown-toggle buttons or the logout form. Each enhanced link
keeps its existing `href`, so navigation remains available without JavaScript.

## Behaviour

Livewire 4.2 intercepts clicks on the enhanced links and restores browser
history, scroll position, and focus using its built-in navigation lifecycle.
The hover modifier asks Livewire to prefetch the destination for pointer-based
devices; touch devices continue to navigate on tap without hover prefetch.

Because Livewire swaps the full document during navigation, the existing
Alpine dropdown roots are recreated for each destination. No custom navigation
hooks or global JavaScript initialization will be added, avoiding duplicate
listeners and stale component state.

## Verification

Add a feature test that renders an authenticated page for each applicable role
and asserts that visible primary-navigation anchors retain their destinations
and carry exactly one `wire:navigate.hover` attribute. Existing feature tests
cover deep links and Livewire component rendering; run the focused test and
the complete Laravel suite after the change.
