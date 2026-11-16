/**
 * Saha Takip ekranlarının ortak yardımcıları (Firmalar, Görevler).
 *   $.SHT.post(modulePath, action, data, onOk)  → sunucu isteği; resCode 0 ise onOk, değilse hata penceresi
 *   $.SHT.confirm(type, text, onYes)            → Evet / Hayır penceresi
 *   $.SHT.error(text)                           → hata penceresi
 *   $.SHT.coord(text)                           → "38.4254, 27.1487" → [38.4254, 27.1487] (geçersizse null)
 * Dil metinleri ekranın yüklediği dil dosyalarından (getLangDatas) alınır.
 */
$.SHT = {
    lang: function () { return (typeof getLangDatas === 'function' && getLangDatas()) || {}; },

    error: function (text) {
        $.fn.ntyp({
            type: 'error',
            text: text,
            modal: true,
            buttons: [{ addClass: 'btn btn-sm btn-danger', text: $.SHT.lang()['BTN_OK_TEXT'] || 'Tamam' }]
        });
    },

    post: function (modulePath, action, data, onOk) {
        const L_ = $.SHT.lang();
        $.ajaxq('frame', {
            url: proxyPath + modulePath + action,
            type: 'POST',
            dataType: 'json',
            data: data,
            beforeSend: function () { $('.frame-block').blk(); },
            success: function (result) {
                if (result != null && result['resCode'] === 0) {
                    onOk(result);
                } else {
                    $.SHT.error((L_['INF_ERROR'] || 'Hata') + ': #' + (result && result['resCode']) + ' : ' + (result && result['resMsg']));
                }
            },
            complete: function () { $('.frame-block').ublk(); },
            error: function (xhr) { $('.frame-block').ublk(); $.SHT.error(xhr); }
        });
    },

    confirm: function (type, text, onYes) {
        const L_ = $.SHT.lang();
        $.fn.nty({
            type: type,
            text: text,
            buttons: [
                { text: L_['BTN_YES_TEXT'] || 'Evet', onClick: function ($noty) { $noty.close(); onYes(); } },
                { text: L_['BTN_NO_TEXT'] || 'Hayır' }
            ]
        });
    },

    coord: function (text) {
        const m = String(text || '').trim().match(/^(-?\d{1,2}(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:[.,]\d+)?)$/);
        if (!m) { return null; }
        const lat = parseFloat(m[1].replace(',', '.'));
        const lon = parseFloat(m[2].replace(',', '.'));
        return (Math.abs(lat) <= 90 && Math.abs(lon) <= 180) ? [lat, lon] : null;
    }
};
