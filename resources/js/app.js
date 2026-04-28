import './bootstrap';

document.addEventListener('livewire:init', () => {
    // Android WebView may serve a stale cached page from a previous APK install,
    // causing Livewire's snapshot checksum to fail (419). Reload silently instead
    // of showing the native "This page has expired" confirm dialog.
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                preventDefault()
                window.location.replace('/')
            }
        })
    })
})
