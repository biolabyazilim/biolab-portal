/**
 * Saha Takip — Canlı harita
 *
 * Canlı: aktif saha personelinin son konumları haritada (yeşil = son 10 dk içinde konum geldi, sarı = daha eski).
 *        Liste ve harita 15 sn'de bir yenilenir (getLive).
 * Rota:  soldan personel seçilir, gün seçilir → o günün bütün konumları yön oklarıyla çizilir,
 *        başlangıç / bitiş işaretlenir, mesafe ve saat aralığı yazılır (getRoute).
 * Planlama: Canlı + bütün firmalar haritada (🏢; renk: açık görev yok / görev bekliyor / personel firmada).
 *        Sol liste Müsait / Görevde / Müsait değil gruplu. Firmaya tıklayınca altta firma kartı:
 *        açık görevleri ve en yakın müsait personel (müsait = mesaide + konum taze + üstünde Yolda/Firmada görev yok).
 *        Firmalar 30 sn'de bir yenilenir (getCustomers).
 * Harita: Leaflet + OpenStreetMap. Konum verisi portal üzerinden Traccar'dan gelir (php/harita-1.0.0.php).
 */
const modulePath = 'app=../../../apps/biolab-saha-takip/harita/php/harita-1.0.0@';
const langFiles = ['general.json', 'sht_harita/harita-1.0.0.json'];
const REFRESH_MS = 15000;   // harita yenileme; konumun tazeliğini asıl belirleyen telefonun gönderme sıklığı (2 dk)
// Akıllı sıklık: yolda olan personel varken telefon 20 sn'de bir gönderir → harita da daha sık yenilenir
const REFRESH_FAST_MS = 10000;
const DEFAULT_CENTER = [39.0, 35.0];   // Türkiye (henüz konum yokken)
const FIRM_REFRESH_MS = 30000;         // Firmalar katmanı: firmalar ve açık görevleri
const FIRMS_KEY = 'sht.harita.firmsOn'; // "Firmalar" düğmesinin son durumu (tarayıcıda)
const ARROW_COLOR = '#ea7a0c';         // "Yolda" rengi (görev listesindeki "Yolda" rozetiyle aynı); paletin ilk rengi
// Yoldaki her kişiye kendi rota rengi: birbirinden ve harita durum renklerinden (yeşil / gri / mor) ayırt edilebilir 8 renk
const ROUTE_PALETTE = ['#ea7a0c', '#0284c7', '#db2777', '#0d9488', '#b45309', '#dc2626', '#1e3a8a', '#4d7c0f'];
const OFFROUTE_M = 300;                // rotaya bundan uzaksa "rotadan çıktı" (sunucu da bu sınırda yeniden hesaplar)
const AWAY_FROM_SITE_M = 300;         // "Firmada" görünen personel firmadan bundan uzaksa ⚠ firmada değil (telefondaki Vardım uyarısıyla aynı)

$(document).ready(function () {
    $.DLNFrame.init();
    if(workInFrame){
        $.DLN.LangManager.loadLanguageResource(langFiles);
    }else {
        $.DLN.loginManager.checkLoginTimer($.DLN.loginManager, function () {
            $.DLN.LangManager.loadLanguageResource(langFiles);
        });
    }
});

$.DLNFrame = {
    data:{
        lang:null,
        map:null,
        people:[],          // getLive kayıtları
        markers:{},         // personelId → Leaflet işareti
        trails:{},          // personelId → son 30 dk hareket izi (kesik çizgi)
        taskArrows:{},      // personelId → "Yolda" ise gittiği firmaya ok
        selectedId:null,
        mode:'live',        // 'live' (canlı harita; firmalar katman olarak açılıp kapanır) | 'route' (geçmiş rota)
        firmsOn:true,       // "Firmalar" düğmesi; tarayıcıda hatırlanır
        timer:null,
        customers:[],       // Firmalar: getCustomers kayıtları
        firmMarkers:{},     // customerId → firma işareti
        selectedFirmId:null,
        firmTimer:null,
        assignPersonelId:null,   // firma kartında atanacak kişi (yenilemelerde korunur)
        assignLines:null,        // firmadan adaylara kesik çizgiler
        planFitted:false,
        routeLayer:null,
        fitted:false        // ilk yüklemede harita bütün personele sığdırılır
    },

    init: function () {
        const $t = $.DLNFrame;
        $('#routeDate').val(moment().format('YYYY-MM-DD')).attr('max', moment().format('YYYY-MM-DD'));

        try { $t.data.firmsOn = localStorage.getItem(FIRMS_KEY) !== '0'; } catch (e) { /* gizli pencere: varsayılan açık */ }

        $('body').off('langLoaded.lang').on('langLoaded.lang', function () {
            $t.data.lang = getLangDatas();
            $.DLN.LangManager.setLanguageTemplate($(document));
            $('#btnFirms').attr('title', $t.data.lang['BTN_FIRMS_TIP']);
            $t.initMap();
            $t.initSideToggle();
            // Mesailer ekranındaki "Rota" düğmesinden: ?personel=1&date=2026-11-12 → doğrudan rota modu
            const q = new URLSearchParams(window.location.search);
            const pid = parseInt(q.get('personel'), 10);
            if (pid > 0) {
                $t.data.selectedId = pid;
                if (/^\d{4}-\d{2}-\d{2}$/.test(q.get('date') || '')) { $('#routeDate').val(q.get('date')); }
                $t.data.mode = 'route';
                $t.loadLive(function () { $t.setRouteMode(); });   // önce liste (ad soyad), sonra rota
            } else {
                $t.setLive();
            }
        });

        $('#btnModeLive').click(function () { $t.setLive(); });
        $('#btnModeRoute').click(function () { $t.setRouteMode(); });
        $('#btnFirms').click(function () { $t.setFirms(!$t.data.firmsOn); });
        $('#btnClosePanel').click(function () { $t.selectFirm(null); });
        $('#btnAssign').click(function () { $t.assign(); });
        $('#asTitle').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $t.assign(); } });
        $('#btnShowRoute').click(function () { $t.loadRoute(); });
        $('#routeDate').change(function () { if ($t.data.selectedId) { $t.loadRoute(); } });
        $('#btnFitAll').click(function () { $t.fitAll(); });
        $('#mpSearch').on('input', function () { $t.renderList(); });
    },

    initMap: function () {
        const $d = $.DLNFrame.data;
        $d.map = L.map('map', { zoomControl: true }).setView(DEFAULT_CENTER, 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo($d.map);
    },

    // Sunucu isteği (proxy). Hata mesajı sağ üstte gösterilir.
    post: function (action, data, onOk) {
        $.ajaxq('frame', {
            url: proxyPath + modulePath + action,
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (result) {
                if (result != null && (result['resCode'] === 0 || result['resCode'] === 21)) {
                    onOk(result);
                } else {
                    $.fn.ntyi({ type: 'error', layout: 'topRight', text: (result && result['resMsg']) || 'Hata' });
                }
            },
            error: function () {
                $.fn.ntyi({ type: 'error', layout: 'topRight', text: 'Sunucuya ulaşılamadı.' });
            }
        });
    },

    // ---------- Canlı ----------

    /** Firmalar haritada görünüyor mu: canlı haritada ve "Firmalar" düğmesi açıksa */
    firmsVisible: function () {
        const $d = $.DLNFrame.data;
        return $d.mode === 'live' && $d.firmsOn;
    },

    /**
     * Mod düğmeleri ve moda özel araçlar. Firmalar katmanı yalnız canlı haritada ve düğme açıkken görünür;
     * değilse firmalar gizlenir, yenileme durur, açık firma kartı kapanır.
     */
    setModeUi: function (mode) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $d.mode = mode;
        [['live', '#btnModeLive'], ['route', '#btnModeRoute']].forEach(function (b) {
            $(b[1]).toggleClass('btn-primary', b[0] === mode).toggleClass('btn-outline-primary', b[0] !== mode);
        });
        $('#routeControls').prop('hidden', mode !== 'route');
        $('#btnFirms').prop('hidden', mode !== 'live').attr('aria-pressed', $d.firmsOn ? 'true' : 'false');
        $('#planControls').prop('hidden', !$t.firmsVisible());
        if (!$t.firmsVisible()) {
            clearInterval($d.firmTimer);
            $d.firmTimer = null;
            $t.showFirms(false);
            if ($d.selectedFirmId !== null) { $t.selectFirm(null); }
        }
    },

    setLive: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.setModeUi('live');
        $('#mpSummary').text('');
        $t.clearRoute();
        $t.showMarkers(true);
        clearTimeout($d.timer);
        $t.liveLoop();
        if ($d.firmsOn) { $t.startFirms(); }
    },

    /**
     * Canlı yenileme döngüsü: yolda olan personel varsa 10 sn, yoksa 15 sn sonra tekrar.
     * (Sabit setInterval yerine; aralık her turda son veriye göre seçilir. Rota moduna geçince durur.)
     */
    liveLoop: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if ($d.mode !== 'live') { return; }
        $t.loadLive();
        const anyOnTheWay = $d.people.some(function (p) { return p.activeTask && p.activeTask.status === 'ON_THE_WAY'; });
        $d.timer = setTimeout($t.liveLoop, anyOnTheWay ? REFRESH_FAST_MS : REFRESH_MS);
    },

    // ---------- Firmalar katmanı (görev atama) ----------

    /** "Firmalar" düğmesi: aç → firmalar + atama; kapat → sadece personel takibi */
    setFirms: function (on) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $d.firmsOn = on;
        try { localStorage.setItem(FIRMS_KEY, on ? '1' : '0'); } catch (e) { /* yoksay */ }
        $t.setModeUi($d.mode);
        if ($t.firmsVisible()) { $t.startFirms(); } else { $('#mpSummary').text(''); }
        $t.renderList();
    },

    startFirms: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.showFirms(true);
        $t.loadCustomers();
        clearInterval($d.firmTimer);
        $d.firmTimer = setInterval($t.loadCustomers, FIRM_REFRESH_MS);
    },

    loadCustomers: function () {
        const $t = $.DLNFrame;
        $t.post('getCustomers', {}, function (result) {
            $t.data.customers = result['records'] || [];
            $t.renderFirms();
            // İstek dönerken firmalar kapatıldıysa / rotaya geçildiyse bir şey gösterme
            if (!$t.firmsVisible()) { $t.showFirms(false); return; }
            if ($t.data.selectedFirmId !== null) { $t.renderPanel(); }
            $t.planSummary();
            if (!$t.data.planFitted) { $t.fitAll(); $t.data.planFitted = true; }
        });
    },

    /** Müsait = mesaide + konum taze + üstünde Yolda / Firmada görev yok */
    availability: function (p) {
        if (p.activeTask) { return 'busy'; }
        return (p.onShift && p.status === 'online') ? 'available' : 'unavailable';
    },

    /** İki nokta arası kuş uçuşu (metre) */
    distanceM: function (lat1, lon1, lat2, lon2) {
        const r = 6371000, rad = Math.PI / 180;
        const dLat = (lat2 - lat1) * rad, dLon = (lon2 - lon1) * rad;
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) ** 2;
        return 2 * r * Math.asin(Math.min(1, Math.sqrt(a)));
    },

    /** 25 → "25 dk", 95 → "1 sa 35 dk" */
    durationText: function (min) {
        const L_ = $.DLNFrame.data.lang;
        const h = Math.floor(min / 60);
        return (h > 0 ? h + ' ' + L_['HOUR_SHORT'] + ' ' : '') + (min % 60) + ' ' + L_['MIN_SHORT'];
    },

    kmText: function (m) {
        return m < 1000 ? Math.round(m) + ' m' : (m / 1000).toFixed(1).replace('.', ',') + ' km';
    },

    selectedFirm: function () {
        const $d = $.DLNFrame.data;
        return $d.customers.find(function (c) { return c.customerId === $d.selectedFirmId; }) || null;
    },

    /**
     * "Firmada" görünen personel canlı konumuna göre firmadan uzaksa uzaklık (m), değilse null.
     * Her konum güncellemesinde bakılır: Vardım deyip hiç gitmeyen de, vardıktan sonra ayrılan da yakalanır.
     * GPS doğruluğu kadar pay bırakılır (en fazla 100 m). Sınır telefonla aynı: 300 m.
     */
    awayFromSiteM: function (p) {
        const k = p.activeTask;
        if (!k || k.status !== 'ON_SITE' || k.lat == null || p.lat == null) { return null; }
        const m = $.DLNFrame.distanceM(p.lat, p.lon, k.lat, k.lon);
        return m - Math.min(p.accuracy || 0, 100) > AWAY_FROM_SITE_M ? m : null;   // GPS payı en fazla 100 m (telefon / portal ile aynı)
    },

    /** Personelin seçili firmaya uzaklığı (firma ya da personel konumu yoksa null) */
    distanceToFirm: function (p, firm) {
        if (!firm || firm.lat == null || p.lat == null) { return null; }
        return $.DLNFrame.distanceM(p.lat, p.lon, firm.lat, firm.lon);
    },

    firmIcon: function (c) {
        const $d = $.DLNFrame.data;
        const esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
        const n = (c.tasks || []).length;
        // Personel firmadaysa: nabız halkası + etikette ne kadar süredir orada ("ABC Tekstil · 25 dk")
        const onSite = c.state === 'ON_SITE';
        const since = onSite && c.onSiteSince ? moment().diff(moment(c.onSiteSince, 'YYYY-MM-DD HH:mm:ss'), 'minutes') : null;
        const label = esc(c.customerName) + (since != null ? ' · ' + $.DLNFrame.durationText(Math.max(0, since)) : '');
        return L.divIcon({
            className: '',
            html: '<div class="fm-mk fm-' + c.state + (c.customerId === $d.selectedFirmId ? ' sel' : '') + '">'
                + (onSite ? '<div class="fm-pulse"></div>' : '')
                + '<div class="fm-box"><i class="fas fa-building"></i>' + (n > 0 ? '<b>' + n + '</b>' : '') + '</div>'
                + '<div class="fm-lbl">' + label + '</div></div>',
            iconSize: [140, 54],
            iconAnchor: [70, 18]   // kutunun ortası konumda
        });
    },

    renderFirms: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const seen = {};
        $d.customers.forEach(function (c) {
            if (c.lat == null) { return; }
            seen[c.customerId] = true;
            let m = $d.firmMarkers[c.customerId];
            if (m) {
                m.setLatLng([c.lat, c.lon]);
                const icon = $t.firmIcon(c);
                // Yalnız görünümü değiştiyse yeniden çiz (yoksa mor nabız her 30 sn'de baştan başlar)
                if (m.options.icon.options.html !== icon.options.html) { m.setIcon(icon); }
            }
            else {
                m = $d.firmMarkers[c.customerId] = L.marker([c.lat, c.lon], { icon: $t.firmIcon(c), title: c.customerName, zIndexOffset: -500 })
                    .on('click', function () { $t.openFirmPopup($t.data.customers.find(function (x) { return x.customerId === c.customerId; }) || c); });
                if ($t.firmsVisible()) { m.addTo($d.map); }
            }
        });
        Object.keys($d.firmMarkers).forEach(function (id) {
            if (!seen[id]) { $d.map.removeLayer($d.firmMarkers[id]); delete $d.firmMarkers[id]; }
        });
    },

    showFirms: function (show) {
        const $d = $.DLNFrame.data;
        Object.keys($d.firmMarkers).forEach(function (id) {
            if (show) { $d.firmMarkers[id].addTo($d.map); } else { $d.map.removeLayer($d.firmMarkers[id]); }
        });
    },

    /**
     * Firma seçimi (null = seçimi kaldır, paneli ve pencereyi kapat).
     * openPanel: true → sağdan (telefonda alttan) ayrıntı paneli açılır; false → sadece seçilir (çizgiler, sıralı liste).
     * Panel haritanın üstünde açıldığı için firma panelin altında kalmasın diye harita kaydırılır.
     */
    selectFirm: function (id, openPanel) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (id !== $d.selectedFirmId) { $d.assignPersonelId = null; }   // yeni firma: en yakın müsait yeniden seçilsin
        $d.selectedFirmId = id;
        const open = id !== null && (openPanel || $('#planPanel').hasClass('open'));
        $('#planPanel').toggleClass('open', open).attr('aria-hidden', open ? 'false' : 'true');
        $t.renderFirms();
        $t.renderList();
        if (id === null) { $t.clearAssignLines(); $d.map.closePopup(); return; }
        if (!$('#asDate').val()) { $('#asDate').val(moment().format('YYYY-MM-DD')); }
        $t.renderPanel();
        if (open) { $t.revealFirm(); }
    },

    /** Firmayı panelin kapatmadığı alana getir: masaüstü/tablette sola, telefonda yukarı kaydır */
    revealFirm: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const c = $t.selectedFirm();
        if (!c || c.lat == null) { return; }
        const panel = document.getElementById('planPanel');
        const phone = window.matchMedia('(max-width: 767px)').matches;
        const map = $d.map;
        // Pencere açılırken başlayan kaydırma ile çakışmasın: süren hareketi durdur, MUTLAK hedefe git
        map.stop();
        const zoom = map.getZoom();
        const size = map.getSize();
        // Hedef: panel dışında kalan alanın ortası (telefonda panelin üstü, diğerlerinde solu)
        const target = phone
            ? L.point(size.x / 2, Math.max(40, (size.y - panel.offsetHeight) / 2))
            : L.point(Math.max(60, (size.x - panel.offsetWidth) / 2), size.y / 2);
        // Firma hedef noktada dursun diye haritanın yeni merkezi: firma - (hedef - ekran ortası)
        const firmPx = map.project([c.lat, c.lon], zoom);
        const center = firmPx.subtract(target.subtract(size.divideBy(2)));
        map.panTo(map.unproject(center, zoom), { animate: true });
    },

    // ---------- Firma üstünde hızlı atama penceresi (popup) ----------

    /**
     * Firmaya dokununca firmanın ÜSTÜNDE açılır: ad, açık görev / firmadaki personel bilgisi, görev adı kutusu ve
     * en yakın 3 müsait personel (her birinde "Ata"). Atamaların çoğu buradan tek dokunuşla biter;
     * "Ayrıntılar" bütün görevleri, gün/saat ve tüm personeli içeren paneli açar.
     */
    openFirmPopup: function (c) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L_ = $d.lang;
        $t.selectFirm(c.customerId, false);   // seçim: çizgiler + sol listede uzaklık sırası
        const el = $('<div>');
        el.append($('<div class="fp-name">').text(c.customerName));
        const n = (c.tasks || []).length;
        const since = c.state === 'ON_SITE' && c.onSiteSince ? moment().diff(moment(c.onSiteSince, 'YYYY-MM-DD HH:mm:ss'), 'minutes') : null;
        const $meta = $('<div class="fp-meta">').text(n > 0 ? L_['POP_OPEN_TASKS'].replace('%d', n) : L_['NO_OPEN_TASKS']);
        if (since != null) { $meta.append(' · ', $('<span class="fp-onsite">').text(L_['POP_ON_SITE'].replace('%s', $t.durationText(Math.max(0, since))))); }
        el.append($meta);

        const $title = $('<input type="text" class="form-control form-control-sm fp-title" maxlength="200">')
            .attr('placeholder', L_['PH_ASSIGN_TASK']).val($d.lastTitle || '');
        el.append($title);
        // Firmada kalınacak saat aralığı (zorunlu): son girilen hazır gelir
        const $start = $('<input type="time" class="form-control form-control-sm">').attr('aria-label', L_['PH_TIME_START']).val($d.lastStart || '');
        const $end = $('<input type="time" class="form-control form-control-sm">').attr('aria-label', L_['PH_TIME_END']).val($d.lastEnd || '');
        el.append($('<div class="fp-time">').append($('<span class="fp-time-lbl">').text(L_['POP_TIME']), $start, $('<span>').text('–'), $end));
        el.append($('<div class="fp-cap">').text(L_['POP_NEAREST']));

        const top = $t.assignCandidates(c).filter(function (x) { return x.av === 'available'; }).slice(0, 3);
        if (!top.length) { el.append($('<div class="fp-empty">').text(L_['NO_AVAILABLE'])); }
        top.forEach(function (x, i) {
            $('<div class="fp-person">')
                .append($('<span class="fm-rank">').text(i + 1))
                .append($('<span class="fp-who">').text(x.p.fullName + ' (' + x.p.personelCode + ')'))
                .append($('<span class="fp-km">').text(x.m == null ? '' : $t.kmText(x.m)))
                .append($('<button type="button" class="btn btn-sm btn-primary">').text(L_['BTN_POP_ASSIGN'])
                    .on('click', function () { $t.assignQuick(c, x.p, $title.val().trim(), $title, $start, $end); }))
                .appendTo(el);
        });
        el.append($('<button type="button" class="btn btn-sm btn-outline-secondary fp-more">')
            .html('<i class="fas fa-list"></i> ' + $('<div>').text(L_['BTN_DETAILS']).html())
            .on('click', function () { $d.map.closePopup(); $t.selectFirm(c.customerId, true); }));

        L.popup({ className: 'fm-popup', maxWidth: 320, minWidth: 250, offset: [0, -12], autoPanPadding: [24, 24] })
            .setLatLng([c.lat, c.lon]).setContent(el[0]).openOn($d.map);
        // Tablette klavye hemen açılıp haritayı kapatmasın: yalnız fare ile kullanılan ekranda odaklan
        if (!window.matchMedia('(pointer: coarse)').matches) { setTimeout(function () { $title.trigger('focus'); }, 50); }
    },

    /** Saat aralığı kontrolü: ikisi de dolu ve bitiş başlangıçtan sonra. Hata varsa mesaj + kutuya odak, false döner. */
    checkTimes: function ($start, $end) {
        const L_ = $.DLNFrame.data.lang;
        const s = $start.val(), e = $end.val();
        $start.toggleClass('is-invalid', !s);
        $end.toggleClass('is-invalid', !e || (s && e <= s));
        if (!s || !e) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: L_['ERR_TIME'] }); (!s ? $start : $end).trigger('focus'); return false; }
        if (e <= s) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: L_['ERR_END_BEFORE_START'] }); $end.trigger('focus'); return false; }
        return true;
    },

    /** Penceredeki "Ata": bugüne, girilen saat aralığıyla görev açar ve atar */
    assignQuick: function (c, p, title, $title, $start, $end) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!title) {
            $.fn.ntyi({ type: 'error', layout: 'topRight', text: $d.lang['ERR_TITLE'] });
            $title.addClass('is-invalid').trigger('focus');
            return;
        }
        if (!$t.checkTimes($start, $end)) { return; }
        $t.post('setAssign', {
            CUSTOMER_ID: c.customerId, PERSONEL_ID: p.personelId, TITLE: title,
            PLANNED_DATE: moment().format('YYYY-MM-DD'), PLANNED_TIME: $start.val(), PLANNED_END_TIME: $end.val(), DESCRIPTION: ''
        }, function (result) {
            $d.lastTitle = title;   // sonraki atamada hazır gelsin (aynı işi birkaç firmaya dağıtırken)
            $d.lastStart = $start.val();
            $d.lastEnd = $end.val();
            $t.assignedToast(result, { title: title, customerName: c.customerName, personel: p.fullName });
            $d.map.closePopup();
            $t.loadCustomers();
        });
    },

    // ---------- Görev iptali (yanlış atama) ----------

    /**
     * Atama başarılı mesajı: sağ üstte 15 sn kalır, içinde "Görevi iptal et" düğmesi.
     * Yanlış kişiye / firmaya atandıysa koordinatör ekran değiştirmeden hemen geri alabilir.
     */
    assignedToast: function (result, task) {
        const $t = $.DLNFrame;
        const taskId = result['resObject'] && result['resObject']['TASK_ID'];
        const n = noty({
            type: 'success',
            layout: 'topRight',
            text: $('<div>').text(result['resMsg']).html(),
            closeWith: ['button'],
            buttons: taskId ? [{
                addClass: 'btn btn-sm btn-outline-danger',
                text: $t.data.lang['BTN_CANCEL_TASK'],
                onClick: function ($noty) { $noty.close(); $t.cancelTask($.extend({ taskId: taskId }, task)); }
            }, {
                addClass: 'btn btn-sm btn-light',
                text: $t.data.lang['BTN_OK_TEXT'] || 'Tamam',
                onClick: function ($noty) { $noty.close(); }
            }] : false
        });
        setTimeout(function () { try { n.close(); } catch (e) { /* zaten kapandı */ } }, 15000);
    },

    /**
     * Görevi iptal et: önce "Emin misiniz?" (Evet / Hayır). Evet → portalda iptal, personelin telefonuna
     * bildirim gider ve görev telefondaki listeden kalkar; haritada firma rengi / rozet güncellenir.
     * task: { taskId, title, customerName, personel }
     */
    /** Gecikti: bitiş saati geçti, personel henüz varmadı (kayıt değişmez, yalnız etiket). k.plannedEndAt "YYYY-MM-DD HH:mm" */
    isLate: function (k) {
        return ['NEW', 'ASSIGNED', 'ON_THE_WAY'].indexOf(k.status) >= 0 && !!k.plannedEndAt
            && moment(k.plannedEndAt, 'YYYY-MM-DD HH:mm').isBefore(moment());
    },

    cancelTask: function (task) {
        const $t = $.DLNFrame;
        const L_ = $t.data.lang;
        const esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
        const who = [task.customerName, task.personel].filter(Boolean).map(esc).join(' · ');
        $.fn.nty({
            type: 'warning',
            // Personel firmadaysa (Vardım basılmış) yönetici bunu bilerek iptal etsin
            text: (task.status === 'ON_SITE' ? L_['CANCEL_ON_SITE'] + '<br>' : '')
                + L_['CONFIRM_CANCEL_TASK'].replace('%s', esc(task.title)).replace('%s', who),
            buttons: [
                {
                    addClass: 'btn btn-danger', text: L_['BTN_YES_TEXT'] || 'Evet',
                    onClick: function ($noty) {
                        $noty.close();
                        $t.post('setCancel', { TASK_ID: task.taskId }, function (result) {
                            $.fn.ntyi({ type: 'success', layout: 'topRight', text: result['resMsg'] });
                            $t.loadCustomers();
                            $t.loadLive();   // yoldaysa rotası / görev etiketi kalksın
                        });
                    }
                },
                { addClass: 'btn btn-secondary', text: L_['BTN_NO_TEXT'] || 'Hayır', onClick: function ($noty) { $noty.close(); } }
            ]
        });
    },

    // ---------- Personel listesini aç / kapat ----------

    /** Dar ekranda (tablet dikey, telefon) liste başlangıçta kapalı: harita geniş kalsın */
    initSideToggle: function () {
        const $t = $.DLNFrame;
        const $wrap = $('.mp-wrap');
        $wrap.toggleClass('side-closed', window.innerWidth < 1100);
        $('#btnToggleSide').on('click', function () {
            $wrap.toggleClass('side-closed');
            setTimeout(function () { $t.data.map.invalidateSize(); }, 0);
        });
    },

    /** Düğmede müsait sayısı (liste kapalıyken de görünsün) */
    updateSideCount: function () {
        const $t = $.DLNFrame;
        const n = $t.data.people.filter(function (p) { return $t.availability(p) === 'available'; }).length;
        $('#sideCount').text(n > 0 ? n + ' ' + $t.data.lang['AV_AVAILABLE'] : '');
    },

    // ---------- Hızlı görev atama (firma kartı) ----------

    /** Atama listesi: müsaitler firmaya yakından uzağa, sonra görevdekiler, sonra müsait olmayanlar (ada göre) */
    assignCandidates: function (firm) {
        const $t = $.DLNFrame;
        const order = { available: 0, busy: 1, unavailable: 2 };
        return $t.data.people
            .map(function (p) { return { p: p, av: $t.availability(p), m: $t.distanceToFirm(p, firm) }; })
            .sort(function (a, b) {
                return order[a.av] - order[b.av]
                    || (a.av === 'available' ? (a.m == null ? Infinity : a.m) - (b.m == null ? Infinity : b.m) : 0)
                    || a.p.fullName.localeCompare(b.p.fullName, 'tr');
            });
    },

    /** Aday listesini döner; seçim yoksa (ya da seçilen kişi artık listede değilse) en yakın müsaiti seçer */
    ensureAssignSelection: function (firm) {
        const $d = $.DLNFrame.data;
        const list = $.DLNFrame.assignCandidates(firm);
        if (!list.some(function (x) { return x.p.personelId === $d.assignPersonelId; })) {
            const first = list.find(function (x) { return x.av === 'available'; });
            $d.assignPersonelId = first ? first.p.personelId : null;
        }
        return list;
    },

    renderAssignPeople: function (firm) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L_ = $d.lang;
        const list = $t.ensureAssignSelection(firm);
        const $box = $('#asPeople').empty();
        if (!list.length) { $box.append($('<div class="fm-empty px-2">').text(L_['NO_PERSONEL'])); }
        let rank = 0;
        list.forEach(function (x) {
            const isAv = x.av === 'available';
            if (isAv) { rank++; }
            const on = x.p.personelId === $d.assignPersonelId;
            $('<label class="fm-person">').toggleClass('on', on)
                .append($('<input type="radio" name="asPersonel">').val(x.p.personelId).prop('checked', on))
                .append(isAv && rank <= 3 ? $('<span class="fm-rank">').text(rank) : $('<span class="mp-dot">').addClass('st-' + $t.state(x.p)))
                .append($('<span>').text(x.p.fullName + ' (' + x.p.personelCode + ')'))
                .append($('<span class="fm-av">').text(isAv ? '' : L_['AV_' + x.av.toUpperCase()]))
                .append($('<span class="mp-km">').text(x.m == null ? '' : $t.kmText(x.m)))
                .on('change', function () { $d.assignPersonelId = x.p.personelId; $t.renderPanel(); })
                .appendTo($box);
        });
        $t.drawAssignLines(firm, list.filter(function (x) { return x.av === 'available' && x.m != null; }).slice(0, 3));
    },

    /** Firmadan en yakın 3 müsait kişiye kesik çizgi; seçili kişininki kalın, mavi ve uzaklık etiketli */
    drawAssignLines: function (firm, top) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $t.clearAssignLines();
        if (!firm || firm.lat == null) { return; }
        $d.assignLines = L.layerGroup().addTo($d.map);
        top.forEach(function (x) {
            const sel = x.p.personelId === $d.assignPersonelId;
            const line = L.polyline([[firm.lat, firm.lon], [x.p.lat, x.p.lon]], {
                color: sel ? '#1f6f9f' : '#98a2b3', weight: sel ? 4 : 2, opacity: 0.9, dashArray: sel ? '8 6' : '4 6', interactive: false
            }).addTo($d.assignLines);
            if (sel) {
                line.bindTooltip($t.kmText(x.m), { permanent: true, direction: 'center', className: 'fm-km-tip' }).openTooltip();
            }
        });
    },

    clearAssignLines: function () {
        const $d = $.DLNFrame.data;
        if ($d.assignLines) { $d.map.removeLayer($d.assignLines); $d.assignLines = null; }
    },

    /** "Görevi ata": yeni görev aç + seçili personele ata */
    assign: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L_ = $d.lang;
        const title = $('#asTitle').val().trim();
        if (!title) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: L_['ERR_TITLE'] }); $('#asTitle').trigger('focus'); return; }
        if (!$d.assignPersonelId) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: L_['NO_PERSONEL_SELECTED'] }); return; }
        if (!$t.checkTimes($('#asTime'), $('#asTimeEnd'))) { return; }
        $('#btnAssign').prop('disabled', true);
        $t.post('setAssign', {
            CUSTOMER_ID: $d.selectedFirmId,
            PERSONEL_ID: $d.assignPersonelId,
            TITLE: title,
            PLANNED_DATE: $('#asDate').val(),
            PLANNED_TIME: $('#asTime').val(),
            PLANNED_END_TIME: $('#asTimeEnd').val(),
            DESCRIPTION: $('#asDesc').val()
        }, function (result) {
            const sel = $d.people.find(function (p) { return p.personelId === $d.assignPersonelId; });
            $t.assignedToast(result, { title: title, customerName: ($t.selectedFirm() || {}).customerName, personel: sel && sel.fullName });
            $('#asTitle, #asDesc').val('');   // saatler kalsın (aynı aralıkla birkaç atama)
            $('#asTime, #asTimeEnd').removeClass('is-invalid');
            $t.loadCustomers();   // firma rengi / rozet ve açık görevler güncellensin
        });
        setTimeout(function () { $('#btnAssign').prop('disabled', false); }, 1200);
    },

    /** Karttaki atanmamış görevi seçili personele ata */
    assignExisting: function (taskId) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.assignPersonelId) { $.fn.ntyi({ type: 'error', layout: 'topRight', text: $d.lang['NO_PERSONEL_SELECTED'] }); return; }
        $t.post('setAssignExisting', { TASK_ID: taskId, PERSONEL_ID: $d.assignPersonelId }, function (result) {
            const c = $t.selectedFirm() || {};
            const k = (c.tasks || []).find(function (x) { return x.taskId === taskId; }) || {};
            const sel = $d.people.find(function (p) { return p.personelId === $d.assignPersonelId; });
            $t.assignedToast(result, { title: k.title, customerName: c.customerName, personel: sel && sel.fullName });
            $t.loadCustomers();
        });
    },

    /** Firma kartı: bilgiler, açık görevler, hızlı atama */
    renderPanel: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const L_ = $d.lang;
        const c = $t.selectedFirm();
        if (!c) { $t.selectFirm(null); return; }
        $('#fmName').text(c.customerName);
        $('#fmMeta').text([c.address, c.city, c.isgKatipNo ? 'İSG-KATİP ' + c.isgKatipNo : '',
            [c.contactName, c.phone].filter(Boolean).join(' · ')].filter(Boolean).join(' · '));

        const $tasks = $('#fmTasks').empty();
        if (!(c.tasks || []).length) { $tasks.append($('<div class="fm-empty">').text(L_['NO_OPEN_TASKS'])); }
        $t.ensureAssignSelection(c);   // görev satırlarındaki "→ Ad" düğmesi ilk açılışta da seçili kişiyi bilsin
        const sel = $d.people.find(function (p) { return p.personelId === $d.assignPersonelId; });
        (c.tasks || []).forEach(function (k) {
            // "13.11 10:00–12:00" (eski görevlerde bitiş yok: yalnız gün / başlangıç)
            const when = !k.plannedAt ? ''
                : moment(k.plannedAt, 'YYYY-MM-DD HH:mm').format(k.plannedAt.slice(11) === '00:00' && !k.plannedEndAt ? 'DD.MM' : 'DD.MM HH:mm')
                  + (k.plannedEndAt ? '–' + k.plannedEndAt.slice(11) : '');
            const $row = $('<div class="fm-row">')
                .append($('<span class="tk-badge">').addClass('tk-' + k.status).text(L_['ST_' + k.status] || k.status))
                .append($t.isLate(k) ? $('<span class="tk-late">').attr('title', L_['LATE_TIP']).text(L_['LATE']) : null)
                .append($('<span>').text(k.title))
                .append($('<span class="mp-sub ml-auto">').text([when, k.personel || L_['UNASSIGNED']].filter(Boolean).join(' · ')));
            // Atanmamış görev: seçili personele tek tıkla ata
            if (k.status === 'NEW' && sel) {
                $('<button type="button" class="btn btn-outline-primary btn-xs-assign">')
                    .attr('title', L_['TITLE_ASSIGN_EXISTING'])
                    .text(L_['BTN_ASSIGN_EXISTING'].replace('%s', sel.fullName.split(' ')[0]))
                    .on('click', function () { $t.assignExisting(k.taskId); })
                    .appendTo($row);
            }
            // Her açık görevde iptal (yanlış kişi / firma): onaydan sonra iptal, telefondan kalkar
            $('<button type="button" class="btn btn-outline-danger btn-xs-cancel">')
                .attr({ title: L_['TITLE_CANCEL_TASK'], 'aria-label': L_['TITLE_CANCEL_TASK'] })
                .html('<i class="fas fa-times"></i>')
                .on('click', function () { $t.cancelTask({ taskId: k.taskId, title: k.title, customerName: c.customerName, personel: k.personel, status: k.status }); })
                .appendTo($row);
            $row.appendTo($tasks);
        });

        $t.renderAssignPeople(c);
    },

    planSummary: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$t.firmsVisible()) { return; }
        const cnt = { available: 0, busy: 0, unavailable: 0 };
        $d.people.forEach(function (p) { cnt[$t.availability(p)]++; });
        const noLoc = $d.customers.filter(function (c) { return c.lat == null; }).length;
        $('#mpSummary').text($d.lang['PLAN_SUMMARY']
            .replace('%a', cnt.available).replace('%b', cnt.busy).replace('%f', $d.customers.length)
            + (noLoc ? ' · ' + $d.lang['FIRM_NO_LOCATION'].replace('%d', noLoc) : ''));
    },

    loadLive: function (done) {
        const $t = $.DLNFrame;
        $t.post('getLive', {}, function (result) {
            $t.data.people = result['records'] || [];
            $('#mpLastRefresh').text(moment().format('HH:mm:ss'));
            $t.updateSideCount();
            $t.assignRouteColors();   // liste ve harita aynı renkleri kullansın (liste önce çiziliyor)
            $t.renderList();
            $t.renderMarkers();
            if (!$t.data.fitted && $t.data.mode === 'live') { $t.fitAll(); $t.data.fitted = true; }
            if ($t.firmsVisible()) {
                $t.planSummary();
                if ($t.data.selectedFirmId !== null) { $t.renderPanel(); }   // en yakın müsait güncellensin
            }
            if (typeof done === 'function') { done(); }
        });
    },

    statusText: function (p) {
        const L_ = $.DLNFrame.data.lang;
        if (p.status === 'nodevice') { return L_['STATUS_NODEVICE']; }
        if (p.status === 'nodata') { return L_['STATUS_NODATA']; }
        return p.minutesAgo < 1 ? L_['JUST_NOW'] : L_['MIN_AGO'].replace('%d', p.minutesAgo);
    },

    renderList: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const q = ($('#mpSearch').val() || '').toLocaleLowerCase('tr-TR');
        const $list = $('#mpList').empty();
        const people = $d.people.filter(function (p) {
            return !q || (p.fullName + ' ' + p.personelCode).toLocaleLowerCase('tr-TR').indexOf(q) >= 0;
        });
        // Canlı haritada liste hep gruplu (Müsait / Görevde / Müsait değil); geçmiş rotada düz liste
        if ($d.mode === 'live') { $t.renderPlanList($list, people); return; }
        people.forEach(function (p) { $t.listItem(p).appendTo($list); });
    },

    /** Listedeki bir personel satırı. extra: sağda gösterilecek uzaklık / görev bilgisi */
    listItem: function (p, extra) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const sub = p.personelCode + (p.jobType ? ' · ' + p.jobType : '') + ' · ' + $t.statusText(p)
            + (p.status === 'online' && p.speedKmh > 0 ? ' · ' + p.speedKmh + ' km/sa' : '');
        const $info = $('<div>')
            .append($('<div class="mp-name">').text(p.fullName))
            .append($('<div class="mp-shift">').toggleClass('on', !!p.onShift).text($t.shiftText(p)));
        if (p.activeTask) {
            const L_ = $d.lang;
            const onSite = p.activeTask.status === 'ON_SITE';
            $info.append($('<div class="mp-task">')
                .css(onSite ? {} : { color: $t.routeColor(p), fontWeight: 600 })   // yoldaysa haritadaki rota rengi
                .text((onSite ? L_['TASK_ON_SITE'] : L_['TASK_ON_THE_WAY']).replace('%s', p.activeTask.customerName)));
            const away = $t.awayFromSiteM(p);
            if (away != null) {
                $info.append($('<div class="mp-away">').attr('title', L_['AWAY_FROM_SITE_TIP'])
                    .text('⚠ ' + L_['AWAY_FROM_SITE'].replace('%s', $t.kmText(away))));
            }
        }
        $info.append($('<div class="mp-sub">').text(sub));
        const $row = $('<div class="mp-item">')
            .toggleClass('active', p.personelId === $d.selectedId)
            .append($('<span class="mp-dot">').addClass('st-' + $t.state(p)))
            .append($info)
            .on('click', function () { $t.select(p.personelId); });
        if (extra) { $row.append($('<span class="mp-km">').text(extra)); }
        return $row;
    },

    /**
     * Planlama listesi: MÜSAİT / GÖREVDE / MÜSAİT DEĞİL grupları.
     * Firma seçiliyse müsaitler o firmaya en yakından uzağa sıralanır ve uzaklık yazar.
     */
    renderPlanList: function ($list, people) {
        const $t = $.DLNFrame;
        const L_ = $t.data.lang;
        const firm = $t.selectedFirm();
        const groups = { available: [], busy: [], unavailable: [] };
        people.forEach(function (p) {
            groups[$t.availability(p)].push({ p: p, m: $t.distanceToFirm(p, firm) });
        });
        groups.available.sort(function (a, b) {
            return (a.m == null ? Infinity : a.m) - (b.m == null ? Infinity : b.m) || a.p.fullName.localeCompare(b.p.fullName, 'tr');
        });
        [['available', 'GROUP_AVAILABLE'], ['busy', 'GROUP_BUSY'], ['unavailable', 'GROUP_UNAVAILABLE']].forEach(function (g) {
            const list = groups[g[0]];
            $('<div class="mp-group">').text(L_[g[1]] + ' (' + list.length + ')').appendTo($list);
            list.forEach(function (x) {
                $t.listItem(x.p, g[0] === 'available' && x.m != null ? $t.kmText(x.m) : null).appendTo($list);
            });
        });
    },

    /**
     * Renk durumu (liste noktası, iğne ve iz aynı rengi kullanır):
     *   active = mesaide + son 10 dk içinde konum · late = mesaide ama konum gecikti (dikkat)
     *   off = mesai dışı · none = hiç konumu yok
     */
    state: function (p) {
        if (p.lat == null) { return 'none'; }
        if (!p.onShift) { return 'off'; }
        return p.status === 'online' ? 'active' : 'late';
    },

    // Durum renkleri (CSS'teki --st-* ile aynı)
    stateColor: { active: '#16a34a', late: '#ea7a0c', off: '#6b7685', none: '#c2c8d0' },

    // "Mesaide · başlangıç 09:14" / "Mesai dışı" (sht_shift'teki açık mesaiye göre)
    shiftText: function (p) {
        const L_ = $.DLNFrame.data.lang;
        return p.onShift ? L_['ON_SHIFT'].replace('%s', p.shiftSince || '') : L_['OFF_SHIFT'];
    },

    popupHtml: function (p) {
        const L_ = $.DLNFrame.data.lang;
        const esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
        const k = p.activeTask;
        const task = !k ? '' : '<span style="color:' + (k.status === 'ON_SITE' ? '#5b2bb5' : $.DLNFrame.routeColor(p)) + ';font-weight:600">'
            + esc(k.status === 'ON_SITE' ? L_['TASK_ON_SITE'].replace('%s', k.customerName) : $.DLNFrame.taskLeftText(p) + ' ' + L_['LEFT'])
            + '</span><br>' + esc(k.title) + '<br>';
        const away = $.DLNFrame.awayFromSiteM(p);
        const awayHtml = away == null ? ''
            : '<span class="mp-away">⚠ ' + esc(L_['AWAY_FROM_SITE'].replace('%s', $.DLNFrame.kmText(away))) + '</span><br>';
        return '<b>' + esc(p.fullName) + '</b> (' + esc(p.personelCode) + ')<br>'
            + esc($.DLNFrame.shiftText(p)) + '<br>' + task + awayHtml
            + (p.jobType ? esc(p.jobType) + '<br>' : '')
            + L_['LAST_POSITION'] + ': ' + moment(p.fixTime, 'YYYY-MM-DD HH:mm:ss').format('DD.MM.YYYY HH:mm')
            + ' (' + esc($.DLNFrame.statusText(p)) + ')<br>'
            + L_['SPEED'] + ': ' + p.speedKmh + ' km/sa'
            + (p.moving ? ' · <b>' + L_['MOVING'] + '</b>' : '')
            + (p.accuracy != null ? ' · ' + L_['ACCURACY'] + ': ±' + p.accuracy + ' m' : '');
    },

    // İğne: damla (kişi simgesi) + hareket halindeyse yön oku + altında "P002 · Ad"
    markerIcon: function (p) {
        const $d = $.DLNFrame.data;
        const esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
        const firstName = (p.fullName || '').split(' ')[0];
        const dir = p.moving && p.heading != null
            ? '<div class="mp-dir"><i class="fas fa-location-arrow" style="transform:rotate(' + (p.heading - 45) + 'deg)"></i></div>'
            : '';
        // Yoldaysa ad etiketinin altında rota rengi: çizgi ile kişi birbirine bağlansın
        const going = p.activeTask && p.activeTask.status === 'ON_THE_WAY';
        const lblStyle = going ? ' style="border-bottom:3px solid ' + $.DLNFrame.routeColor(p) + '"' : '';
        // "Firmada" ama firmadan uzakta: etiketin başında turuncu ⚠ (ayrıntı listede ve pencerede)
        const away = $.DLNFrame.awayFromSiteM(p) != null ? '<span class="mp-away-ic">⚠</span> ' : '';
        return L.divIcon({
            className: '',
            html: '<div class="mp-mk st-' + $.DLNFrame.state(p) + (p.personelId === $d.selectedId ? ' sel' : '') + '">'
                + (going ? '<div class="mp-pulse"></div>' : '')   // yolda: hafif yeşil nabız
                + '<div class="mp-drop"><i class="fas fa-user"></i></div>' + dir
                + '<div class="mp-lbl"' + lblStyle + '>' + away + esc(p.personelCode) + ' · ' + esc(firstName) + '</div></div>',
            iconSize: [120, 72],
            iconAnchor: [60, 50],   // iğnenin ucu konumda
            popupAnchor: [0, -44]
        });
    },

    /**
     * Yoldaki kişilere rota rengi dağıtır. Renk kişiye SABİT kalır (başka biri varınca diğerlerininki kaymaz):
     * önceden aldığı renk boştaysa onu korur, yoksa personelId'ye göre paletten ilk boş renk.
     * 8'den fazla kişi yoldaysa renkler tekrar eder (etiketteki ad ayırt eder).
     */
    assignRouteColors: function () {
        const $d = $.DLNFrame.data;
        const memo = $d.routeColorMemo || ($d.routeColorMemo = {});
        const used = {};
        $d.routeColor = {};
        const going = $d.people
            .filter(function (p) { return p.activeTask && p.activeTask.status === 'ON_THE_WAY'; })
            .sort(function (a, b) { return a.personelId - b.personelId; });
        // Önce rengi olanlar rengini korusun, sonra yeniler boş renk alsın
        going.forEach(function (p) {
            const c = memo[p.personelId];
            if (c && !used[c]) { used[c] = true; $d.routeColor[p.personelId] = c; }
        });
        going.forEach(function (p) {
            if ($d.routeColor[p.personelId]) { return; }
            let c = null;
            for (let i = 0; i < ROUTE_PALETTE.length; i++) {
                const cand = ROUTE_PALETTE[(p.personelId + i) % ROUTE_PALETTE.length];
                if (!used[cand]) { c = cand; break; }
            }
            c = c || ROUTE_PALETTE[p.personelId % ROUTE_PALETTE.length];
            used[c] = true;
            memo[p.personelId] = $d.routeColor[p.personelId] = c;
        });
    },

    /** Kişinin rota rengi (yolda değilse "Yolda" turuncusu) */
    routeColor: function (p) {
        const map = $.DLNFrame.data.routeColor || {};
        return map[p.personelId] || ARROW_COLOR;
    },

    renderMarkers: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const seen = {};
        $t.assignRouteColors();   // iğne etiketi, rota çizgisi ve listede aynı renk
        $d.people.forEach(function (p) {
            if (p.lat == null) { return; }
            seen[p.personelId] = true;
            const icon = $t.markerIcon(p);
            let m = $d.markers[p.personelId];
            if (m) {
                m.setLatLng([p.lat, p.lon]).setPopupContent($t.popupHtml(p));
                // İğneyi yalnız görünümü değiştiyse yeniden çiz: her 15 sn'de baştan çizilirse nabız animasyonu sıçrar
                if (m.options.icon.options.html !== icon.options.html) { m.setIcon(icon); }
            } else {
                m = L.marker([p.lat, p.lon], { icon: icon, title: p.fullName })
                    .bindPopup($t.popupHtml(p))
                    .on('click', function () { $t.select(p.personelId, true); });
                $d.markers[p.personelId] = m;
                if ($d.mode !== 'route') { m.addTo($d.map); }
            }
            // Üst üste binerse: seçilen kişi en önde, mesaidekiler mesai dışındakilerin önünde
            m.setZIndexOffset(p.personelId === $d.selectedId ? 1000 : (p.onShift ? 500 : 0));

            // Son 30 dk hareket izi (yalnız mesaide + konumu taze olanlar; sunucu doldurur)
            const trail = (p.trail || []);
            let line = $d.trails[p.personelId];
            if (trail.length >= 2) {
                const opt = { color: $t.stateColor[$t.state(p)], weight: 3, opacity: 0.8, dashArray: '6 7', interactive: false };
                if (line) { line.setLatLngs(trail).setStyle(opt); }
                else {
                    line = $d.trails[p.personelId] = L.polyline(trail, opt);
                    if ($d.mode !== 'route') { line.addTo($d.map); }
                }
            } else if (line) {
                $d.map.removeLayer(line);
                delete $d.trails[p.personelId];
            }

            $t.renderTaskArrow(p);
        });
        // Listeden çıkan (pasife alınan) personelin işareti, izi ve görev oku kaldırılır
        [$d.markers, $d.trails, $d.taskArrows].forEach(function (set) {
            Object.keys(set).forEach(function (id) {
                if (!seen[id]) { $d.map.removeLayer(set[id]); delete set[id]; }
            });
        });
    },

    /**
     * "Yolda" olan personelden gittiği firmaya turuncu kesik ok + "Yolda → XYZ · 2,3 km" etiketi.
     * Firmada olan / görevi olmayan / firma konumu girilmemiş → ok yok. Her yenilemede yeniden çizilir.
     */
    renderTaskArrow: function (p) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        const old = $d.taskArrows[p.personelId];
        if (old) { $d.map.removeLayer(old); delete $d.taskArrows[p.personelId]; }
        const k = p.activeTask;
        if (!k || k.status !== 'ON_THE_WAY' || k.lat == null || p.lat == null) { return; }

        const color = $t.routeColor(p);
        const prog = $t.routeProgress(p);
        const layers = [];
        let remaining;
        if (prog) {
            // Yol rotası: gidilen kısım soluk düz, kalan kısım kesik
            if (prog.traveled.length >= 2) {
                layers.push(L.polyline(prog.traveled, { color: color, weight: 4, opacity: 0.35, interactive: false }));
            }
            remaining = L.polyline(prog.remaining, { color: color, weight: 4, opacity: 0.95, dashArray: '10 8', interactive: false });
        } else {
            // Rota yok (hesaplanamadı / servis kapalı): düz ok
            remaining = L.polyline([[p.lat, p.lon], [k.lat, k.lon]], { color: color, weight: 3, opacity: 0.9, dashArray: '10 8', interactive: false });
        }
        const head = L.polylineDecorator(remaining, {
            patterns: [{ offset: '100%', repeat: 0,
                symbol: L.Symbol.arrowHead({ pixelSize: 14, polygon: true, pathOptions: { color: color, fillOpacity: 1, weight: 0 } }) }]
        });
        // Etiket: "Ayşe → Yolda → XYZ Gıda · 3,2 km · ~9 dk", zemin kişinin rota renginde
        const tip = $('<span class="tk-tip">').css('background', color).text($t.taskLeftText(p, true));
        remaining.bindTooltip(tip[0].outerHTML, { permanent: true, direction: 'center', className: 'tk-arrow-tip' });
        layers.push(remaining, head);
        const group = $d.taskArrows[p.personelId] = L.layerGroup(layers);
        if ($d.mode !== 'route') { group.addTo($d.map); }
    },

    /**
     * "Yolda → XYZ Gıda · 3,2 km · ~9 dk" — yol rotası varsa yol üzerinden kalan mesafe ve süre
     * (süre = OSRM tahmini × kalan oran), yoksa kuş uçuşu mesafe. Rotadan saptıysa ⚠.
     */
    taskLeftText: function (p, withName) {
        const $t = $.DLNFrame;
        const L_ = $t.data.lang;
        const k = p.activeTask;
        // withName: haritadaki rota etiketi için kişinin adı başta ("Ayşe → Yolda → XYZ Gıda"); birden çok rota kesişince ayırt edilsin
        const onWay = L_['TASK_ON_THE_WAY'].replace('%s', k.customerName);
        const head = withName ? (p.fullName || '').split(' ')[0] + ' → ' + onWay : onWay;
        const prog = $t.routeProgress(p);
        if (prog) {
            const min = Math.max(1, Math.round(prog.remainingS / 60));
            return head + ' · ' + $t.kmText(prog.remainingM) + ' · ~' + min + ' ' + L_['MIN_SHORT']
                + (prog.offRoute ? ' · ⚠ ' + L_['OFF_ROUTE'] : '');
        }
        const m = (k.lat != null && p.lat != null) ? $t.distanceM(p.lat, p.lon, k.lat, k.lon) : null;
        return head + (m != null ? ' · ' + $t.kmText(m) : '');
    },

    // ---------- Yol rotası (OSRM; sunucu hesaplar, görevde saklanır) ----------

    /** Encoded polyline (hassasiyet 5) → [[lat, lon], ...]. Aynı rota tekrar çözülmesin diye önbellek. */
    decodeRoute: function (encoded) {
        const cache = this.data.routeCache || (this.data.routeCache = {});
        if (cache[encoded]) { return cache[encoded]; }
        const pts = [];
        let i = 0, lat = 0, lon = 0;
        while (i < encoded.length) {
            for (let axis = 0; axis < 2; axis++) {
                let result = 0, shift = 0, b;
                do { b = encoded.charCodeAt(i++) - 63; result |= (b & 0x1f) << shift; shift += 5; } while (b >= 0x20 && i < encoded.length);
                const d = (result & 1) ? ~(result >> 1) : (result >> 1);
                if (axis === 0) { lat += d; } else { lon += d; }
            }
            pts.push([lat / 1e5, lon / 1e5]);
        }
        return (cache[encoded] = pts);
    },

    /**
     * Personelin rota üzerindeki yeri: rotaya en yakın nokta bulunur, rota oradan ikiye bölünür.
     * @return {traveled, remaining, remainingM, remainingS, offRoute} ya da null (rota yok)
     */
    routeProgress: function (p) {
        const $t = $.DLNFrame;
        const k = p.activeTask;
        if (!k || !k.route || !k.route.polyline || p.lat == null) { return null; }
        const pts = $t.decodeRoute(k.route.polyline);
        if (pts.length < 2) { return null; }

        // Düzlem yaklaşımı (personel çevresinde metre): her parçaya izdüşüm, en yakını seç
        const kx = 111320 * Math.cos(p.lat * Math.PI / 180), ky = 110540;
        let best = { d: Infinity, i: 1, t: 0 };
        for (let i = 1; i < pts.length; i++) {
            const ax = (pts[i - 1][1] - p.lon) * kx, ay = (pts[i - 1][0] - p.lat) * ky;
            const bx = (pts[i][1] - p.lon) * kx, by = (pts[i][0] - p.lat) * ky;
            const dx = bx - ax, dy = by - ay, len2 = dx * dx + dy * dy;
            const t = len2 > 0 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / len2)) : 0;
            const px = ax + t * dx, py = ay + t * dy, d = Math.sqrt(px * px + py * py);
            if (d < best.d) { best = { d: d, i: i, t: t }; }
        }
        const a = pts[best.i - 1], b = pts[best.i];
        const proj = [a[0] + (b[0] - a[0]) * best.t, a[1] + (b[1] - a[1]) * best.t];
        const traveled = pts.slice(0, best.i).concat([proj]);
        const remaining = [proj].concat(pts.slice(best.i));

        let remainingM = 0;
        for (let i = 1; i < remaining.length; i++) {
            remainingM += $t.distanceM(remaining[i - 1][0], remaining[i - 1][1], remaining[i][0], remaining[i][1]);
        }
        const total = k.route.distanceM || remainingM || 1;
        return {
            traveled: traveled,
            remaining: remaining,
            remainingM: remainingM,
            remainingS: (k.route.durationS || 0) * Math.min(1, remainingM / total),
            offRoute: best.d > OFFROUTE_M
        };
    },

    // Canlı / Planlama: işaretler + izler + görev okları görünür; rota modunda gizlenir
    showMarkers: function (show) {
        const $d = $.DLNFrame.data;
        [$d.markers, $d.trails, $d.taskArrows].forEach(function (set) {
            Object.keys(set).forEach(function (id) {
                if (show) { set[id].addTo($d.map); } else { $d.map.removeLayer(set[id]); }
            });
        });
    },

    fitAll: function () {
        const $d = $.DLNFrame.data;
        if ($d.mode === 'route' && $d.routeLayer) {
            $d.map.fitBounds($d.routeLayer.getBounds(), { padding: [30, 30] });
            return;
        }
        const pts = $d.people.filter(function (p) { return p.lat != null; }).map(function (p) { return [p.lat, p.lon]; });
        if ($.DLNFrame.firmsVisible()) {   // firmalar açıkken personel + firmalar birlikte sığsın
            $d.customers.forEach(function (c) { if (c.lat != null) { pts.push([c.lat, c.lon]); } });
        }
        if (pts.length === 1) { $d.map.setView(pts[0], 15); }
        else if (pts.length > 1) { $d.map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); }
    },

    // Listeden ya da haritadan personel seçimi. Rota modundaysa rotası yüklenir.
    select: function (personelId, fromMarker) {
        const $t = $.DLNFrame;
        const $d = $t.data;
        $d.selectedId = personelId;
        $t.renderList();
        $t.renderMarkers();
        if ($d.mode === 'route') { $t.loadRoute(); return; }
        const p = $d.people.find(function (x) { return x.personelId === personelId; });
        if (p && p.lat != null && !fromMarker) {
            $d.map.setView([p.lat, p.lon], Math.max($d.map.getZoom(), 15));
            $d.markers[personelId].openPopup();
        }
    },

    // ---------- Rota ----------

    setRouteMode: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        clearTimeout($d.timer);   // canlı yenileme döngüsünü durdur
        $t.setModeUi('route');
        $t.showMarkers(false);
        if ($d.selectedId) { $t.loadRoute(); } else { $('#mpSummary').text($d.lang['SELECT_PERSONEL']); }
    },

    clearRoute: function () {
        const $d = $.DLNFrame.data;
        if ($d.routeLayer) { $d.map.removeLayer($d.routeLayer); $d.routeLayer = null; }
    },

    loadRoute: function () {
        const $t = $.DLNFrame;
        const $d = $t.data;
        if (!$d.selectedId) { $('#mpSummary').text($d.lang['SELECT_PERSONEL']); return; }
        const date = $('#routeDate').val();
        $t.post('getRoute', { PERSONEL_ID: $d.selectedId, DATE: date }, function (result) {
            $t.clearRoute();
            const pts = result['records'] || [];
            const p = $d.people.find(function (x) { return x.personelId === $d.selectedId; });
            if (pts.length === 0) {
                $('#mpSummary').text((p ? p.fullName + ' · ' : '') + $d.lang['NO_ROUTE']);
                return;
            }
            const latlngs = pts.map(function (x) { return [x.lat, x.lon]; });
            const line = L.polyline(latlngs, { color: '#d92d20', weight: 4, opacity: 0.85 });
            const arrows = L.polylineDecorator(line, {
                patterns: [{
                    offset: 25, repeat: 80,
                    symbol: L.Symbol.arrowHead({ pixelSize: 10, polygon: true, pathOptions: { color: '#d92d20', fillOpacity: 1, weight: 0 } })
                }]
            });
            const first = pts[0], last = pts[pts.length - 1];
            const start = L.circleMarker(latlngs[0], { radius: 8, color: '#fff', weight: 2, fillColor: '#22a052', fillOpacity: 1 })
                .bindTooltip($d.lang['ROUTE_START'] + ' ' + first.time);
            const end = L.circleMarker(latlngs[latlngs.length - 1], { radius: 8, color: '#fff', weight: 2, fillColor: '#d92d20', fillOpacity: 1 })
                .bindTooltip($d.lang['ROUTE_END'] + ' ' + last.time);
            $d.routeLayer = L.featureGroup([line, arrows, start, end]).addTo($d.map);
            $d.map.fitBounds(line.getBounds(), { padding: [30, 30] });

            const o = result['resObject'];
            $('#mpSummary').text($d.lang['ROUTE_SUMMARY']
                .replace('%s', p ? p.fullName : '')
                .replace('%d', o.pointCount)
                .replace('%s', String(o.distanceKm).replace('.', ','))
                .replace('%s', o.firstTime)
                .replace('%s', o.lastTime));
        });
    }
};
