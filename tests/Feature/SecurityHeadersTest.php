<?php

// Framing must stay restricted to Asana + self: the session cookie is
// SameSite=None (for the extension's iframe overlay on app.asana.com),
// so this header is what keeps arbitrary sites from embedding the app.

test('web responses restrict framing to Asana and self', function () {
    $this->get('/login')->assertHeader(
        'Content-Security-Policy',
        "frame-ancestors 'self' https://app.asana.com"
    );
});
