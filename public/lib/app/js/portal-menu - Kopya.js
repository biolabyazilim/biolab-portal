/**
 * Biolab Portal — ortak uygulama menüsü
 *
 * Menü api/auth.php?action=menu&app=<uygulama>'dan gelir; sunucu kullanıcının rolüne göre süzer
 * (mng_user_role → mng_role_form → mng_form, ağaç mng_menu). Bu dosya yalnızca çizer ve gezinmeyi yönetir.
 *
 *   const menu = PortalMenu.create({ app, api, layout, nav, crumbs, toggle, backdrop, collapseBtn, onOpen });
 *   menu.load().then(function (res) { ... res.first, res.roles ... });
 *   menu.setActive(url);      // çerçevede açılan ekranı menüde işaretler
 *
 * Bağımlılık yok (jQuery gerekmez). Stil: lib/app/css/portal-menu.css
 */
(function (window, document) {
    'use strict';

    function storeGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function storeSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* gizli pencere vb. */ }
    }

    // Menü ikonu veritabanından gelir; yalnızca sınıf adı karakterlerine izin verilir
    function iconEl(icon, fallback) {
        const i = document.createElement('i');
        i.className = 'pm-icon ' + (/^[\w\s-]+$/.test(icon || '') ? icon : fallback);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function labelEl(text) {
        const s = document.createElement('span');
        s.className = 'pm-label';
        s.textContent = text;
        return s;
    }

    function create(opt) {
        const prefix = 'portalMenu.' + opt.app + '.';
        const leaves = [];          // { node, path: [üst menü adları], el }
        let groupState = {};        // menuId → açık mı (kullanıcı tercihi)
        let activeUrl = null;

        try { groupState = JSON.parse(storeGet(prefix + 'groups') || '{}') || {}; } catch (e) { groupState = {}; }
        if (storeGet(prefix + 'collapsed') === '1') { opt.layout.classList.add('is-collapsed'); }

        function render(items) {
            opt.nav.textContent = '';
            leaves.length = 0;
            items.forEach(function (n) { opt.nav.appendChild(renderNode(n, [])); });
        }

        function renderNode(node, path) {
            const li = document.createElement('li');
            const children = node.children || [];

            if (!children.length) {
                const a = document.createElement('a');
                a.className = 'pm-item';
                a.href = node.url || '#';
                a.title = node.name;
                a.dataset.url = node.url || '';
                a.appendChild(iconEl(node.icon, 'fas fa-circle'));
                a.appendChild(labelEl(node.name));
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    open(node.url);
                });
                li.appendChild(a);
                leaves.push({ node: node, path: path, el: a, li: li });
                return li;
            }

            li.className = 'pm-group';
            li.dataset.menuId = node.menuId;
            const expanded = groupState[node.menuId] !== false;   // varsayılan: açık
            li.classList.toggle('is-expanded', expanded);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pm-group-btn';
            btn.title = node.name;
            btn.setAttribute('aria-expanded', String(expanded));
            btn.appendChild(iconEl(node.icon, 'fas fa-folder'));
            btn.appendChild(labelEl(node.name));
            const chev = document.createElement('i');
            chev.className = 'pm-chevron fas fa-chevron-right';
            chev.setAttribute('aria-hidden', 'true');
            btn.appendChild(chev);
            btn.addEventListener('click', function () {
                const now = !li.classList.contains('is-expanded');
                li.classList.toggle('is-expanded', now);
                btn.setAttribute('aria-expanded', String(now));
                groupState[node.menuId] = now;
                storeSet(prefix + 'groups', JSON.stringify(groupState));
            });
            li.appendChild(btn);

            const ul = document.createElement('ul');
            children.forEach(function (c) { ul.appendChild(renderNode(c, path.concat(node.name))); });
            li.appendChild(ul);
            return li;
        }

        function findLeaf(url) {
            const page = String(url || '').split('?')[0];
            return leaves.filter(function (l) { return l.node.url === page; })[0] || null;
        }

        function setActive(url) {
            const leaf = findLeaf(url);
            activeUrl = leaf ? leaf.node.url : null;

            leaves.forEach(function (l) {
                l.el.classList.toggle('is-active', l === leaf);
                if (l === leaf) { l.el.setAttribute('aria-current', 'page'); } else { l.el.removeAttribute('aria-current'); }
            });
            opt.nav.querySelectorAll('.pm-group').forEach(function (g) {
                const has = !!(leaf && g.contains(leaf.li));
                g.classList.toggle('has-active', has);
                if (has) { g.classList.add('is-expanded'); }   // seçili ekranın grubu her zaman açık
            });
            renderCrumbs(leaf);
            if (leaf) { storeSet(prefix + 'last', leaf.node.url); }
        }

        function renderCrumbs(leaf) {
            if (!opt.crumbs) { return; }
            opt.crumbs.textContent = '';
            if (!leaf) { return; }
            leaf.path.concat(leaf.node.name).forEach(function (name, i, all) {
                if (i > 0) {
                    const sep = document.createElement('i');
                    sep.className = 'pm-crumb-sep fas fa-chevron-right';
                    sep.setAttribute('aria-hidden', 'true');
                    opt.crumbs.appendChild(sep);
                }
                const s = document.createElement('span');
                s.textContent = name;
                if (i === all.length - 1) { s.className = 'pm-crumb-current'; }
                opt.crumbs.appendChild(s);
            });
        }

        function open(url) {
            if (!url) { return; }
            setActive(url);
            closeDrawer();
            if (typeof opt.onOpen === 'function') { opt.onOpen(url); }
        }

        function closeDrawer() {
            opt.layout.classList.remove('is-open');
            if (opt.toggle) { opt.toggle.setAttribute('aria-expanded', 'false'); }
        }

        // Mobil çekmece
        if (opt.toggle) {
            opt.toggle.addEventListener('click', function () {
                const isOpen = opt.layout.classList.toggle('is-open');
                opt.toggle.setAttribute('aria-expanded', String(isOpen));
            });
        }
        if (opt.backdrop) { opt.backdrop.addEventListener('click', closeDrawer); }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeDrawer(); } });

        // Masaüstü: yalnızca ikon görünümü
        if (opt.collapseBtn) {
            opt.collapseBtn.addEventListener('click', function () {
                const c = opt.layout.classList.toggle('is-collapsed');
                storeSet(prefix + 'collapsed', c ? '1' : '0');
            });
        }

        function load() {
            opt.nav.innerHTML = '<li class="pm-loading">Menü yükleniyor…</li>';
            return window.fetch(opt.api + '?action=menu&app=' + encodeURIComponent(opt.app), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            }).then(function (r) {
                return r.json().then(function (body) { return { status: r.status, body: body }; });
            }).then(function (res) {
                if (!res.body || !res.body.ok) {
                    opt.nav.textContent = '';
                    return { ok: false, status: res.status, items: [], first: null, roles: [] };
                }
                const items = res.body.menu || [];
                render(items);
                // İlk açılacak ekran: son açılan (hâlâ yetkiliyse), yoksa menüdeki ilk ekran
                const last = findLeaf(storeGet(prefix + 'last'));
                return {
                    ok: true,
                    items: items,
                    first: last ? last.node.url : (leaves[0] ? leaves[0].node.url : null),
                    roles: res.body.roles || []
                };
            });
        }

        return {
            load: load,
            open: open,
            setActive: setActive,
            has: function (url) { return !!findLeaf(url); },
            activeUrl: function () { return activeUrl; }
        };
    }

    window.PortalMenu = { create: create };

})(window, document);
