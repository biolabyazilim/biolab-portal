/**
 * Biolab — KVKK sayfası (public-kvkk/index.html)
 *
 * - Sayfa açılınca php/api.php'den yayındaki başlıklar ve belgeler (JSON) $.ajaxq ile alınır.
 * - Her başlık için index.html'deki <template id="tplNode">, her belge için <template id="tplDocument"> kopyalanıp doldurulur.
 *   Görünümü değiştirmek için JS'e değil, index.html'deki şablonlara ve css/kvkk.css'e bakılır.
 * - Açılıp kapanma tarayıcının <details> etiketiyle olur; burada ayrı kod yok.
 * - Metinler .text() ile yazılır (HTML olarak yorumlanmaz), yönetimde girilen bir başlık sayfayı bozamaz.
 * Bu site portalın lib klasörüne erişemediği için jQuery ve ajaxq js/lib klasörüne kopyalandı.
 */
$(document).ready(function () {
    KvkkPage.init();
});

const KvkkPage = {
    opt: {
        apiUrl: 'php/api.php',
        tree: '#kvkkTree',
        loading: '#kvkkLoading',
        empty: '#kvkkEmpty',
        error: '#kvkkError',
        tplNode: '#tplNode',
        tplDocument: '#tplDocument'
    },

    // Sayfa açılışı: veriyi ister, gelince ağacı çizer
    init: function () {
        const $t = KvkkPage;
        $t.loadTree();
    },

    // api.php'den başlıkları alır. Cevaba göre ağaç, "belge yok" ya da hata mesajı gösterilir.
    loadTree: function () {
        const $t = KvkkPage;
        const $o = $t.opt;
        $.ajaxq('frame',
            {
                url: $o.apiUrl,
                type: 'GET',
                dataType: 'json',
                success: function (result) {
                    if (result == null || result['ok'] !== true) {
                        $t.showState($o.error);
                        return;
                    }
                    const nodes = result['nodes'];
                    if (nodes == null || nodes.length === 0) {
                        $t.showState($o.empty);
                        return;
                    }
                    $t.renderNodes(nodes, $($o.tree));
                    $t.showState(null);
                },
                error: function (xhr) {
                    console.error('KVKK verisi alınamadı:', xhr.status, xhr.statusText);
                    $t.showState($o.error);
                }
            }
        );
    },

    // Bir başlık listesini verilen kutuya çizer (alt başlıklar için kendini tekrar çağırır)
    renderNodes: function (nodes, $container) {
        const $t = KvkkPage;
        $.each(nodes, function (i, node) {
            $container.append($t.createNode(node));
        });
    },

    // Tek başlık: şablonu kopyalar; adı, açıklamayı, belgeleri ve alt başlıkları doldurur
    createNode: function (node) {
        const $t = KvkkPage;
        const $el = $($($t.opt.tplNode).html());

        $el.find('.kv-node-name').text(node['name']);
        // Açıklama başlığın altında küçük yazı olarak durur; boşsa alan boş kalır, CSS (:empty) gizler
        $el.find('.kv-node-desc').text(node['desc'] || '');

        const $docList = $el.find('.kv-docs');
        $.each(node['documents'] || [], function (i, doc) {
            $docList.append($t.createDocument(doc));
        });

        $t.renderNodes(node['children'] || [], $el.find('.kv-children'));
        return $el;
    },

    // Tek belge: şablonu kopyalar; adı ve PDF bağlantısını (php/doc.php?k=…) yazar
    createDocument: function (doc) {
        const $t = KvkkPage;
        const $el = $($($t.opt.tplDocument).html());

        $el.find('.kv-doc-link').attr({
            href: doc['url'],
            title: doc['name'] + ' (PDF, yeni sekmede açılır)'
        });
        $el.find('.kv-doc-name').text(doc['name']);
        return $el;
    },

    // Yükleniyor / belge yok / hata mesajlarından yalnızca verileni gösterir (null = hiçbiri)
    showState: function (selector) {
        const $o = KvkkPage.opt;
        $.each([$o.loading, $o.empty, $o.error], function (i, s) {
            $(s).prop('hidden', s !== selector);
        });
    }
};
