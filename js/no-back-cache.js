/**
 * When user logs out and presses back, browser may restore page from bfcache.
 * This forces a reload so the server can redirect to login.
 */
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        window.location.reload();
    }
});
