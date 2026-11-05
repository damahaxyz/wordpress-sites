/* Carry the selected store across classic menu forms and asynchronous pickers. */
(() => {
    const c = window.gsmNativeMenus;
    if (!c) return;
    const fields = {gsm_brand: String(c.brand), gsm_menu_scope_nonce: c.nonce};
    document.querySelectorAll('form').forEach(form => {
        if (form.closest('#gsm-menu-store')) return;
        for (const [name, value] of Object.entries(fields)) {
            let input = form.querySelector(`[name="${name}"]`);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden'; input.name = name; form.appendChild(input);
            }
            input.value = value;
        }
    });
    jQuery.ajaxPrefilter(options => {
        const data = typeof options.data === 'string' ? new URLSearchParams(options.data) : new URLSearchParams(options.data || {});
        if (!c.actions.includes(data.get('action'))) return;
        for (const [name, value] of Object.entries(fields)) data.set(name, value);
        options.data = data.toString();
    });
})();
