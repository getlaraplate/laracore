<script>
    (() => {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const flag = 'core.browser-timezone.sent';

        if (! timezone) {
            return;
        }

        try {
            if (sessionStorage.getItem(flag) === '1') {
                return;
            }

            sessionStorage.setItem(flag, '1');
        } catch (error) {
            // Storage is blocked: the script still runs once per page load.
        }

        fetch(@js($url), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @js($token),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ timezone }),
        }).catch(() => {});
    })();
</script>
