/**
 * Created by ferhat on 20.02.2017.
 */
// Biolab: site kökü. Ekranlar farklı derinlikte (apps/<uygulama>/<ekran>/) olduğu için
// proxy, dil ve eklenti dosyaları köke göre çağrılır. Site bir alt klasörde yayınlanırsa sadece burası değişir.
let appRootPath = '/';
let proxyPath = appRootPath + 'lib/php/proxy/jsonp.php?';
const $window = window;
let workInFrame = false;
// noinspection JSUnusedGlobalSymbols
const clientEvent = {
    select: 'patientSelect',//'selectClient.client',
    main: 'patientSelect'//'.client'
};
if(window != null) {
    if (window.top != null) {
        // workInFrame = window === window.top ? false : true;//TODO NEW-CLASS
        workInFrame = window !== window.top;//TODO NEW-CLASS
    }
}

//Language Manager
function getDLN() {
    if(typeof ($.DLN ) == 'undefined'){
        if(window.parent !== window){//TODO NEW-CLASS
            $.DLN = window.parent.$.DLN;
            if(typeof ($.DLN ) == 'undefined'){
                $.DLN = {};
                $.DLN.Name = "innerFrameWork";
            }
        }else{
            // console.log($.DLN);
            if(typeof ($.DLN ) == 'undefined'){
                $.DLN = {};
                $.DLN.Name = "innerFrameWork";
            }
        }
    }
    return $.DLN;
}
$.DLN = getDLN();

if (typeof ($.DLN.loginManager ) === 'undefined') {
    $.DLN.loginManager = {
        options: {
            loginDetectError: 0,
            timeOutReDetect: 30,
            timeOutCont: [],
            loggedData: {
                userId: null,
                companyId: null,
                companies: [],
                companyDetail: {},
                companyName: null,
                isOwner: false,
                lang: 'tr',
                langCultureInfo: 'tr',
                userInfo: '.user-info',
                personName:'',
                personSurname: ''

            }
        },
        checkLoginTimer: function (obj, callback) {
            let $this;
            if (typeof (obj) === 'undefined' || obj === null) {
                $this = this;
            } else {
                $this = obj;
            }
            const o = $this.options;
            const modulePath = 'app=loginUser@';
            $.ajaxq('widget',
                {
                    url: proxyPath + modulePath + 'checkLoginTimer',
                    contentType: 'application/x-www-form-urlencoded',
                    type: 'POST',
                    dataType: 'json',
                    success: function (result) {
                        if (result['resCode'] === 0) {//TODO NEW-CLASS
                            const session = result['s'];
                            if (session['sTimeout'] === true) {//TODO NEW-CLASS
                                $this.showLoginScreen();
                            } else {
                                o.loginDetectError = 0;
                            }
                            o.loggedData.companyId = session['companyId'];
                            o.loggedData.companyName = session['companyName'];
                            o.loggedData.isOwner = session['isOwner'];
                            // o.loggedData.lang = session['lang'];
                            $.DLN.loginManager.setLang(session['lang']);
                            // o.loggedData.langCultureInfo = session['langCultureInfo'];
                            $.DLN.loginManager.setLangCultureInfo(session['langCultureInfo']);
                            o.loggedData.userId = session['userId'];
                            o.loggedData.personName = session['personName'];
                            o.loggedData.personSurname = session['personSurname'];
                        }
                        $this.startLoginDetect();
                        if (callback) {
                            callback();
                        }
                    },
                    error: function () {
                        o.loginDetectError++;
                        if (o.loginDetectError > 5) {
                            $this.showLoginScreen();
                        }
                        $this.startLoginDetect();
                        if (callback) {
                            callback();
                        }
                    }
                }
            );
        },
        startLoginDetect: function () {
            const $this = this;
            const o = $this.options;
            $this.stopLoginDetect();
            o.timeOutCont.push(setTimeout($this.checkLoginTimer, o.timeOutReDetect * 1000 * 60, $this));
        },
        stopLoginDetect: function () {
            const $this = this;
            const o = $this.options;
            for (let i = 0; i < o.timeOutCont.length; i++) {
                clearTimeout(o.timeOutCont[i]);
            }
            o.timeOutCont = [];
        },
        getLang: function () {
            const $this = this;
            const o = $this.options;
            return o.loggedData.lang;
        },
        getLangCultureInfo: function () {
            const $this = this;
            const o = $this.options;
            return o.loggedData.langCultureInfo;
        },
        showLoginScreen: function () {
            $('.frame-block').blk({message: 'Please Login'});
        },
        checkLogin : function () {
            // console.log('false checklogin');

        },


        setLangCultureInfo: function (val) {
            const $this = this;
            const o = $this.options;
            if(o.loggedData.langCultureInfo !== val) {//TODO NEW-CLASS
                // moment.locale(val);
                o.loggedData.langCultureInfo = val;
                $('body').trigger('langCultureInfoChanged');
            }
        },
        setLang: function (val) {
            const $this = this;
            const o = $this.options;
            if(o.loggedData.lang !== val) {//TODO NEW-CLASS
                o.loggedData.lang = val;
                $.DLN.LangManager.loadGeneralResource();
                $('body').trigger('langChanged');
            }
        },
    }
}

$( document ).ajaxSuccess(function( event, xhr ) {
    let result = null;
    try{
        result = $.parseJSON(xhr.responseText );
    }catch(err){
    }
    let $lm = $.DLN.loginManager;
    try{
        if(result){
            if(result['s']){
                const session = result['s'];
                if(session == null){
                    $lm.checkLogin();
                }
                // o.loggedData.lang = session['lang'];
                $.DLN.loginManager.setLang(session['lang']);
                // o.loggedData.langCultureInfo = session['langCultureInfo'];
                $.DLN.loginManager.setLangCultureInfo(session['langCultureInfo']);

                if(session['companyId'] && session['userId']){
                    if($lm.options.loggedData.companyId !== session['companyId'] ||
                        $lm.options.loggedData.userId !== session['userId']){//TODO NEW-CLASS
                        $lm.checkLogin();
                    }
                }
                if(session['sTimeout'] === true){//TODO NEW-CLASS
                    $lm.showLoginScreen();
                }else{
                    $lm.options.timeOutReDetect = session['sTimeoutReDetect'];
                    $lm.startLoginDetect();
                }
            }
        }
    }catch(err){

    }
});
/*
function getClientId() {
    if(typeof ($.DLN.clientManager ) !== 'undefined') {
        return $.DLN.clientManager.event.data.id;
    }else{
        return null;
    }
}
*/

if(!parent.window.$('#modalPrintPage').hasClass('show'))
{
    parent.window.$('.patient-search-container').addClass('d-none');
}
function getClientId() {
    if(typeof ($.DLN.clientManager ) !== 'undefined') {
        return $.DLN.clientManager.event.data.id;
    }else{
        if(parent.window !== window)//TODO NEW-CLASS
            return parent.window.getClientId();
        else
            return null;
    }
}
// noinspection JSUnusedGlobalSymbols
function getCompanyId() {
    if(typeof ($.DLN.loginManager ) !== 'undefined') {
        return $.DLN.loginManager.options.loggedData.companyId;
    }else{
        return null;
    }
}
function getLangDatas() {
    return $.DLN.options.LM.lang;
}
/*if(typeof ($.DLN.LangManager ) !== 'undefined'){
 $.DLN.LangManager = window.parent.$.DLN.LangManager;
 }else {*/
$.DLN.LangManager = {
    options: {
        resourcePath: 'lib/app/resources',
        lastLoadedStatus: false
    },
    /*data: {
     gnLang: null,
     lcLang: null,
     lang: null,
     loaded: false
     },*/
    loadGeneralResource: function () {
        const $this = this;
        $this.initLangValue();
        let lang;
        if (typeof ($.DLN.loginManager ) == 'undefined') {
            lang = 'tr';
        } else {
            lang = $.DLN.loginManager.getLang();
        }/*
        $.ajaxq('lang',
            {
                url: proxyPath + 'app=loginUser@' + 'loadResourceFile',
                data:{resourceFile:'../../lib/app/resources/' + lang + '/general.json'},
                type: "POST",
                dataType: "json",
                success: function (result) {
                    $.DLN.options.LM.gnLang = result;
                    $.DLN.options.LM.lang = $.extend(true,$.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                    // $('body').trigger('langLoadedGeneral');
                },
                complete: function () {
                    $('body').trigger('langLoadedGeneral.lang');
                }
            }
        );*/
        let generalJsonPath = 'lib/app/resources/' + lang + '/general.json';
        if(!workInFrame){
            generalJsonPath = appRootPath + 'lib/app/resources/' + lang + '/general.json';
            if (typeof isMainFL !== 'undefined') {
                generalJsonPath = 'lib/app/resources/' + lang + '/general.json';
            }
        }
        if (typeof isMainFL !== 'undefined') {

            // $.ajaxq('lang',
            $.ajax(
                {
                    url: generalJsonPath,
                    type: "GET",   // Biolab: statik dosya; IIS düz dosyaya POST'u 405 ile reddeder
                    dataType: "json",
                    success: function (result) {
                        $.DLN.options.LM.gnLang = result;
                        $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                        // $('body').trigger('langLoadedGeneral');

                        $.DLN.LangManager.setLanguageTemplate();
                    },
                    complete: function () {
                        $('body').trigger('langLoadedGeneral.lang');
                    }
                }
            );
        }
    },
    initLangValue : function () {
        if(typeof ($.DLN.options ) === 'undefined'){
            $.DLN.options  = {
                LM:{
                    gnLang: null,
                    lcLang: null,
                    lang: null,
                    loaded:false
                }
            };
        }
    },
    loadLanguageResource: function (filePath, resourcePath) {

        let workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                // workInFrame = window === window.top ? false : true;//TODO NEW-CLASS
                workInFrame = window !== window.top;//TODO NEW-CLASS
            }
        }
        let pathname;
        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
            if (typeof (pathname ) == 'undefined') {
                pathname = "/module/";
            }

        }else{
            pathname = "/module/";
        }

        const $this = this;
        const o = this.options;
        $this.initLangValue();
        let lang;
        if (typeof ($.DLN.loginManager ) == 'undefined') {
            lang = 'en';
        } else {
            lang = $.DLN.loginManager.getLang();
        }
        $.DLN.options.LM.loaded = false;
        if(lang === null){
            lang = 'en';
        }
        resourcePath = (typeof resourcePath !== 'undefined') ? resourcePath : o.resourcePath;
        if(resourcePath.charAt(0) !== '/' && resourcePath.indexOf('../') !== 0) resourcePath = appRootPath + resourcePath; // Biolab: köke göre
        let pluginsArray = null;
        let $fig = 0;

        if($.isArray(filePath)){
            // Biolab: general.json sadece üst pencere gerçekten yüklediyse atlanır (kabuk lib.js yüklemiyor)
            if(workInFrame && $.DLN.options.LM.gnLang && filePath.indexOf('general.json') !== -1)
            filePath.splice(filePath.indexOf('general.json'), 1);
            for(let $fi=0; $fi<filePath.length; $fi++) {
                try{/*
                    $.ajaxq('lang',
                        {
                            url: proxyPath + 'app=loginUser@' + 'loadResourceFile',
                            data:{resourceFile: resourcePath + '/' + lang + '/' + filePath[$fi]},
                            type: "POST",
                            dataType: "json",
                            success: function (result) {
                                $.DLN.options.LM.lcLang = result;
                                $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.lang, $.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                                $.DLN.options.lastLoadedStatus = true;
                            },
                            complete: function () {
                                $fig++;
                                if(filePath.length === $fig) {//TODO NEW-CLASS
                                    pluginsArray = $this.getLanguageForPluginsArray();
                                    if (pluginsArray.length === 0) {//TODO NEW-CLASS
                                        $('body').trigger('langLoaded.lang');
                                    } else {
                                        $this.loadLanguageForPlugins(pluginsArray);
                                    }
                                }
                            },
                            error: function () {
                                $.DLN.options.lastLoadedStatus = false;
                            }
                        }
                    );*/

                        $.ajaxq('lang',
                            {
                                url: resourcePath + '/' + lang + '/' + filePath[$fi],
                                type: "GET",   // Biolab: statik dosya; IIS düz dosyaya POST'u 405 ile reddeder
                                dataType: "json",
                                success: function (result) {
                                    $.DLN.options.LM.lcLang = result;
                                    $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.lang, $.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                                    $.DLN.options.lastLoadedStatus = true;
                                },
                                complete: function () {
                                    $fig++;
                                    if (filePath.length === $fig) {//TODO NEW-CLASS
                                        pluginsArray = $this.getLanguageForPluginsArray();
                                        if (pluginsArray.length === 0) {//TODO NEW-CLASS
                                            $('body').trigger('langLoaded.lang');
                                        } else {
                                            $this.loadLanguageForPlugins(pluginsArray);
                                        }
                                    }
                                },
                                error: function () {
                                    $.DLN.options.lastLoadedStatus = false;
                                }
                            }
                        );

                } catch(error){
                    // Log error
                }
            }
        }else{
            /*$.ajaxq('lang',
                {
                    url: proxyPath + 'app=loginUser@' + 'loadResourceFile',
                    data:{resourceFile: resourcePath + '/' + lang + '/' + filePath},
                    type: "POST",
                    dataType: "json",
                    success: function (result) {
                        // $this.data.lcLang = result;
                        // $.DLN.options.LM.lcLang = result;
                        // $this.data.lang = $.extend(true, $this.data.gnLang, $this.data.lcLang);
                        // $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.gnLang, $this.data.lcLang);

                        $.DLN.options.LM.lcLang = result;
                        $.DLN.options.LM.lang = $.extend(true,$.DLN.options.LM.lang,$.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                        $.DLN.options.lastLoadedStatus = true;


                    },
                    complete: function () {

                        pluginsArray = $this.getLanguageForPluginsArray();
                        if (pluginsArray.length === 0) {//TODO NEW-CLASS

                        } else {
                            $this.loadLanguageForPlugins(pluginsArray);
                        }
                    },
                    error: function () {
                        $.DLN.options.lastLoadedStatus = false;
                    }
                }
            );*/
            $.ajaxq('lang',
                {
                    url: resourcePath + '/' + lang + '/' + filePath,
                    type: "GET",   // Biolab: statik dosya; IIS düz dosyaya POST'u 405 ile reddeder
                    dataType: "json",
                    success: function (result) {
                        // $this.data.lcLang = result;
                        // $.DLN.options.LM.lcLang = result;
                        // $this.data.lang = $.extend(true, $this.data.gnLang, $this.data.lcLang);
                        // $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.gnLang, $this.data.lcLang);

                        $.DLN.options.LM.lcLang = result;
                        $.DLN.options.LM.lang = $.extend(true,$.DLN.options.LM.lang,$.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                        $.DLN.options.lastLoadedStatus = true;


                    },
                    complete: function () {

                        pluginsArray = $this.getLanguageForPluginsArray();
                        if (pluginsArray.length === 0) {//TODO NEW-CLASS

                        } else {
                            $this.loadLanguageForPlugins(pluginsArray);
                        }
                    },
                    error: function () {
                        $.DLN.options.lastLoadedStatus = false;
                    }
                }
            );
        }


    },
    setLanguageTemplate: function (jqElement, data) {
        if($.DLN.options.lastLoadedStatus === false) return;//TODO NEW-CLASS
        data = (typeof data !== 'undefined') ? data : $.DLN.options.LM.lang;
        jqElement = (typeof jqElement !== 'undefined') ? jqElement : $(document);
        function fixElem($this) {
            if (data != null) {
                if (data.hasOwnProperty($this.attr("tr"))) {
                    $this.text(data[$this.attr("tr")]);
                    if ($this.prop('tagName') === 'LABEL') {
                        $this.attr('title', data[$this.attr("tr")]);
                    }
                }
                if (data.hasOwnProperty($this.attr("data-tr"))) {
                    $this.text(data[$this.attr("data-tr")]);
                    if ($this.prop('tagName') === 'LABEL') {
                        $this.attr('title', data[$this.attr("data-tr")]);
                    }
                }
            }
        }

        function fixElemPH($this) {
            if (data != null) {
                if (data.hasOwnProperty($this.attr("trph"))) {
                    $this.attr('placeholder', data[$this.attr("trph")]);
                }
                if (data.hasOwnProperty($this.attr("data-trph"))) {
                    $this.attr('placeholder', data[$this.attr("data-trph")]);
                }
            }
        }
        function fixElemTitle($this) {
            if (data != null) {
                if (data.hasOwnProperty($this.attr("trti"))) {
                    $this.attr('title', data[$this.attr("trti")]);
                }
                if (data.hasOwnProperty($this.attr("data-trti"))) {
                    $this.attr('title', data[$this.attr("data-trti")]);
                }
            }
        }

        function fixElemDM($this) {
            if (data != null) {
                if (data.hasOwnProperty($this.attr("trdm"))) {
                    $this.attr('data-msg', data[$this.attr("trdm")]);
                }
                if (data.hasOwnProperty($this.attr("data-trdm"))) {
                    $this.attr('data-msg', data[$this.attr("data-trdm")]);
                }
            }
        }

        jqElement.find('script[type="x-handlebars-template"]').each(function () {

            let regex;
            // let key;
            let html = $(this).html();

            for (let key in data) {
                regex = new RegExp('{{tr="'+key+'"}}', "igm");
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            for (let key in data) {
                regex = new RegExp('{{data-tr="' + key + '"}}', "igm");
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            for (let key in data) {
                regex = new RegExp('data-tr=' + key + '', "igm");
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            for (let key in data) {
                regex = new RegExp('data-tr="' + key + '"', "igm");
                
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }


            // Biolab: .text() ile yaz; .html() <tr> ile başlayan şablonu tablo olarak ayrıştırıp {{#each}} satırlarını siliyordu
            $(this).text(html);
        });
        jqElement.find('script[type="text/x-handlebars-template"]').each(function () {

            let regex;
            let key;
            let html = $(this).html();

            for (key in data) {
                regex = new RegExp('{{tr="'+key+'"}}', "igm");
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            for (key in data) {
                regex = new RegExp('{{data-tr="' + key + '"}}', "igm");
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            for (let key in data) {
                regex = new RegExp('data-tr=' + key + '', "igm");
                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            for (let key in data) {
                regex = new RegExp('data-tr="' + key + '"', "igm");

                // noinspection JSUnfilteredForInLoop
                html = html.replace(regex, data[key]);
            }
            $(this).text(html);   // Biolab: bkz. yukarıdaki not
        });

        jqElement.find("*[tr],*[data-tr]").each(function () {
            fixElem($(this));
        });

        jqElement.find("*[trph],*[data-trph]").each(function () {
            fixElemPH($(this));
        });

        jqElement.find("*[trdm],*[data-trdm]").each(function () {
            fixElemDM($(this));
        });
        jqElement.find("*[trti],*[data-trti]").each(function () {
            fixElemTitle($(this));
        });

        $('input').attr('autocomplete','off');

    },
    getLanguageForPluginsArray: function () {
        let lang;
        if (typeof ($.DLN.loginManager ) == 'undefined') {
            lang = 'en';
        } else {
            lang = $.DLN.loginManager.getLang();
        }
        if(lang === null){
            lang = 'en';
        }
        let pathname;
        /* if (typeof ($window.location ) !== 'undefined') {
             pathname = $window.location.pathname;
         }*/
        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
            if (typeof (pathname ) == 'undefined') {
                pathname = "/module/company/";
            }

        }else{
            pathname = "/module/company/";
        }
        let prefix = '';
        let workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                // workInFrame = window === window.top ? false : true;//TODO NEW-CLASS
                workInFrame = window !== window.top;//TODO NEW-CLASS
            }
        }
        prefix = appRootPath; // Biolab: köke göre
        const pluginsArray = [];
        // if (pathname.indexOf('module') !== -1) {
        pluginsArray.push(prefix+'lib/plugins/jqGrid-4.6.0/js/i18n/grid.locale-' + lang + '.js');
        pluginsArray.push(prefix+'lib/plugins/jquery-validation-1.19.1/js/localization/messages_' + lang + '.js');

        // } else {
        //     pluginsArray.push('lib/plugins/jqGrid-4.6.0/js/i18n/grid.locale-' + lang + '.js');
        // }
        return pluginsArray;
    },
    loadLanguageForPlugins: function (pluginsArray) {
        $(pluginsArray).each(function (i, e) {
            $.ajaxq('lang', {
                url: e,
                dataType: "script",
                success: function () {
                    //$('body').trigger('langLoaded');
                },
                complete: function () {
                    // $('body').off('langLoaded.lang');
                    if(pluginsArray.length === (i+1)) {
                        // console.log($.DLN.loginManager.getLangCultureInfo());

                        moment.locale($.DLN.loginManager.getLangCultureInfo());
                        $('body').trigger('langLoaded.lang');
                    }
                }
            })
        });
        if(pluginsArray.length === 0){//TODO NEW-CLASS
            // $('body').off('langLoaded.lang');
            $('body').trigger('langLoaded.lang')
        }
    }
};
// }
//Plugin initializer
$.DLN.plugin = {
    resizeTO:null,
    resizeFixer:function(){
        $('.autoheight').each(function (i, e) {


            if($(e).is(':visible')) {
                const siblings = $(e).siblings();
                let siblingHeightSum = 0;
                $(e).outerHeight(0);
                // noinspection JSUnresolvedFunction
                siblings.each(function (si, se) {//TODO NEW-CLASS
                    if ($(se).is(':visible') &&
                        !$(se).hasClass('without-autoheight') &&
                        // !$(se).hasClass('autoheight') &&
                        !$(se).hasClass('modal') &&
                        // !$(se).hasClass('modal-backdrop') &&
                        $(se).prop('tagName') !== 'script' &&
                        $(se).prop('tagName') !== 'head' &&
                        $(se).prop('tagName') != null) {//TODO NEW-CLASS
                        siblingHeightSum += $(se).outerHeight();

                    }
                })

                let content = $(e).parent();
                if($(e).hasClass('modal-content')){
                    siblingHeightSum = -20;
                    content = $('body');
                }
                const contentHeight = content.innerHeight() /*+ parseFloat($(e).css('margin-top'))*/;
                // console.log(contentHeight);
                let contentPadding = 0;
                if($(content).css('position') !== 'fixed') {//TODO NEW-CLASS
                    contentPadding = parseFloat(content.css('padding-top')) +
                        parseFloat(content.css('padding-bottom')) +
                        parseFloat($(e).css('margin-top')) +
                        parseFloat($(e).css('margin-bottom'));
                }
                $(e).outerHeight(contentHeight - siblingHeightSum - contentPadding);
            }
        })
    },
    fixAutoHeight:function () {
        const $this = this;
        $(window, 'body').resize(function () {
            $this.resizeFixer();
        });
        if($this.resizeTO) clearTimeout($this.resizeTO);
        $this.resizeTO = setTimeout($this.resizeFixer, 100);
    },
    init:function() {//Tüm plugin nesnelerini tek bir noktadan ilk tanımlaması buradan yapılacak
        const $t = this;
        // $t.select2.init();
        $t.select2.init();
        $t.dataTable.init();
        $t.datePicker.init();
        $t.fixAutoHeight();
        $t.validation.init();
        //$(document).ajaxStart($.bui).ajaxStop($.unblockUI);
        // const resizeTO = null;
        $t.doubleFixer();
        $('.modal').on('shown.bs.modal', function () {
            $(window).resize()
        })
        $('body').on('click', function(){
            if($(parent.window).length > 0)
                $('.search-result-container', $(parent.window)[0].$('body')).hide();
        });
    } ,
    doubleFixer: function (selector) {
        const $t = this;
        if (typeof ($.DLN.loginManager ) === 'undefined') {
            selector = 'input.double-fix';
        }else{
            selector = 'input.double-fix';
        }
        $(selector).each(function (index, element) {
            $(element).change(function () {
                $(this).val($t.extractNums($(this).val().replace(",", ".")));
            });
        });
    },
    extractNums: function (str) {
        const returnValue = str.match(/[-+.]?[0-9]/g);
        if (returnValue) {
            return returnValue.join("");
        }
        return returnValue;
    },
    directDoubleFix: function (element) {
        const $t = this;
        $(element).change(function () {
            $(this).val($t.extractNums($(this).val().replace(",", ".")));
        });
    }
};

// Modal odağı kendinde tutmasın: modal içindeki select2'nin arama kutusuna yazılabilsin (açılan liste body'de, modal dışında).
// Sayfa yüklenince çalışır (plugin.init hiçbir sayfada çağrılmıyor). Bootstrap 3: enforceFocus, Bootstrap 4: _enforceFocus
if ($.fn.modal && $.fn.modal.Constructor) {
    $.fn.modal.Constructor.prototype.enforceFocus = function() {};
    $.fn.modal.Constructor.prototype._enforceFocus = function() {};
}

$.DLN.plugin.select2 = {
    options : {
        selector: {
            base: 'select.base',
            search: 'select.search'
        }
    },
    init : function () {
        $.fn.modal.Constructor.prototype.enforceFocus = function() {};
        try {
            const o = this.options;

            $(o.selector.base).each(function (i, e) {
                if ($(e).data('select2')) {
                    $(e).select2('destroy');
                }

                $(e).select2({
                    allowClear: true | $(e).data('allowClear'),
                    placeholder: typeof ($(e).attr('placeholder')) === 'undefined' ? '' : $(e).attr('placeholder'),
                    minimumResultsForSearch: -1/*,
                    width: '100%'*/
                });
            })
            $(o.selector.search).each(function (i, e) {
                // console.log($(e).attr('placeholder'));
                if ($(e).data('select2')) {
                    $(e).select2('destroy')
                }
                $(e).select2({
                    allowClear: true,
                    placeholder: typeof ($(e).attr('placeholder')) === 'undefined' ? '' : $(e).attr('placeholder'),
                    minimumResultsForSearch: 0/*,
                    width: '100%'*/
                });
            });
        }catch(e){
            console.log(e);

        }

    }
};
$.DLN.plugin.datePicker = {
    options:{
        selector:{
            base:'div.date-base'
        }
    },
    init: function () {
        const o = this.options;
        const opt = {};
        // opt['useCurrent'] = $(o.selector.base).data('opt-useCurrent');

        $(o.selector.base).dtp(opt);

    }
}
$.DLN.plugin.dataTable = {
    options: {
        selector : {
            ajax : 'table.datatable'
        }
    },
    init : function () {
        const o = this.options;
        const $obj = $(o.selector.ajax);

        const additionalParams = {};
        if ($obj.attr('data-height')){
            additionalParams['scrollY'] = $obj.attr('data-height');
        }
        const defaults = {
            destroy: true,
            retrieve: true,
            processing: true,
            select: 'single'
        };
        const settings = $.extend(true, defaults, additionalParams);
        $(o.selector.ajax).DataTable( settings);
    }
};
$.DLN.plugin.validation = {
    options: {
        selector: {
            base: '.form-validate'
        }
    },
    init: function () {
        const o = this.options;

        if ($(o.selector.base).length > 0) {
            $(o.selector.base).each(function () {

                const id = $(this).attr('id');
                $("#" + id).bind("invalid-form.validate", function(e,v) {
                    // console.log(a);
                    // console.log(v);
                    // console.log(c);
                    v.currentElements.each(function (i, e) {
                        if($(e).prop("tagName") === "SELECT" && $(e).next().hasClass('select2')) {
                            $(e).on('change', function () {//TODO NEW-CLASS
                                $('#'+$(e).attr('id')+'-error').hide();
                            });
                        }
                    })

                }).validate({
                    errorElement  : 'span',
                    errorClass    : 'help-block has-error',
                    errorPlacement: function ( error, element ) {
                        error.addClass( "invalid-feedback" );
                        if(element.prop("tagName") === "SELECT" && element.next().hasClass('select2')){
                            if (element.parent().hasClass('input-group')) {
                                element.parent().after(error);
                            }else {
                                element.next().after(error);
                            }
                        }else {

                            if (element.parent().hasClass('input-group')) {
                                element.parent().after(error);
                            } else {
                                if (element.prop("type") === "checkbox") {
                                    error.insertAfter(element.next("label"));
                                } else {
                                    error.insertAfter(element);
                                }
                            }

                        }

                    }
                });
            });
        }
    }
};
//Select fill and Set Selecct2 Plugin
// (function( $ ){

    //Select fill and Set Selecct2 Plugin
    $.fn.slb = function(options) {
        const defaults = {
            rs: null,
            event: {
                change: null,
                afterLoad: null
            },
            field: {
                text: null,
                value: null,
                disabledFL: null
            },
            beforeClear: true,
            firstNull: true,
            ajax: {
                url: null,
                postData: null,
                form: null
            },
            select2: {
                allowClear: true,
                minimumResultsForSearch: 1,
                openOnEnter: true
            }
        };
        const settings = $.extend(true, defaults, options);
        return this.each(function(i,e) {
            let postData = null;
            if(settings.ajax.url !== null){
                if(settings.ajax.form !== null){
                    postData = $(settings.ajax.form).serialize() + '&' +
                        $.param(settings.ajax.postData) + '&' +
                        $.param({
                            TEXT_FIELD:settings.field.text,
                            VALUE_FIELD:settings.field.value,
                            DISABLED_FIELD:settings.field.disabledFL
                        });
                }else if(settings.ajax.postData !== null){
                    postData = $.param(settings.ajax.postData) + '&' +
                        $.param({
                            TEXT_FIELD:settings.field.text,
                            VALUE_FIELD:settings.field.value,
                            DISABLED_FIELD:settings.field.disabledFL
                        });
                }else{
                    postData = $.param({
                        TEXT_FIELD:settings.field.text,
                        VALUE_FIELD:settings.field.value,
                        DISABLED_FIELD:settings.field.disabledFL
                    });
                }
                $.ajaxq('frame',
                    {
                        url:settings.ajax.url,
                        contentType:"application/x-www-form-urlencoded",
                        data: postData,
                        type:"POST",
                        dataType: "json",
                        success : function( result) {
                            settings.rs = result['records'];
                            fillData(e);
                        }
                    }

                )
            }else{
                fillData(e);
            }
        });
        function fillData(el) {
            const s = settings;

            if(s.beforeClear === true){//TODO NEW-CLASS
                $(el).empty();
            }
            if(s.firstNull === true){//TODO NEW-CLASS
                $(el).append('<option value=""></option>');
            }
            // noinspection HtmlUnknownAttribute
            const tmplData = '{{#each this}}<option {{#xif "this.' + s.field.disabledFL + ' == \'H\' || this.' + s.field.disabledFL + ' == \'N\'"}} disabled {{/xif}} value="{{' + s.field.value + '}}" >{{' + s.field.text + '}}</option>{{/each}}';
            const tmplDataHB = Handlebars.compile(tmplData);
            const tmplDataHTML = tmplDataHB(s.rs);
            $(el).append(tmplDataHTML);

            if(s.select2 != null){
                $(el).select2(s.select2);
            }
            if (typeof s.event.afterLoad === "function") {
                s.event.afterLoad(el);
            }
        }
    };

//Datatable ZeroConfig Call Function
    $.fn.dtbz = function(options) {
        let pathname = "";
        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
        }
        let prefix = '';
        let workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                // workInFrame = window === window.top ? false : true;//TODO NEW-CLASS
                workInFrame = window !== window.top;//TODO NEW-CLASS
            }
        }
        prefix = appRootPath; // Biolab: köke göre;

        const defaults = {
            language: {
                url: prefix + 'lib/plugins/dataTables-1.10.20/i18n/' + $.DLN.loginManager.getLang() + '.lang'
            },
            destroy: true,
            paging: false,
            pageLength: 10,
            scrollY: '244px',
            searching: false,
            select: 'single',
            scrollX: "100%",
            scrollCollapse: true,
            scroller: true,
            autoWidth: false
        };
        $.fn.dataTable.ext.errMode = 'none';
        this.getGlobalId = null;
        const settings = $.extend(true, defaults, options);
        const $t = this;
        return this.each(function(i,e) {
            $t.getGlobalId = $(e).DataTable(settings);
        });
    };
//Datatable Call Function
    $.fn.dtb = function(options) {
        let pathname = "";


        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
        }
        let prefix = '';
        //var workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                // workInFrame = window === window.top ? false : true;//TODO NEW-CLASS
                workInFrame = window !== window.top;//TODO NEW-CLASS
            }
        }
        prefix = appRootPath; // Biolab: köke göre;
        const defaults = {
            language: {
                url: prefix + 'lib/plugins/dataTables-1.10.20/i18n/' + $.DLN.loginManager.getLang() + '.json'
            },
            destroy: true,
            responsive: true,
            retrieve: false,
            processing: true,
            serverSide: true,
            ajax: {
                url: null,
                //dataSrc:'records',
                type: 'POST',
                data: null
            },
            columns: null,
            columnDefs: null,
            // pagingType: 'full',
            order: [[1, 'desc']],
            paging: true,
            //info:false,
            pageLength: 10,
            scrollY: '244px',
            searching: false,
            select: 'single',
            //lengthChange:false,
            /*language:{
                oPaginate:{
                    sFirst:'&#171;',
                    sLast:'&#187;',
                    sNext:'&#155;',
                    sPrevious:'&#139;'
                }
            },*/
            event: {
                select: null,//function ( e, dt, type, indexes ){if ( type === 'row' ) {var data = dateListLabel.rows( indexes ).data()[0]}}
                deselect: null//function ( e, dt, type, indexes ){if ( type === 'row' ) {var data = dateListLabel.rows( indexes ).data()[0]}}
            }
        };
        $.fn.dataTable.ext.errMode = 'none';
        const settings = $.extend(true, defaults, options);
        this.getGlobalId = null;
        const $t = this;
        return this.each(function(i,e) {

            const dateListLabel = $(e).DataTable(settings);
            // console.log(settings);

            /* var parentObj = $('#'+ $(dateListLabel.table().container()).attr('id') +' .dataTables_paginate.paging_full').parent().parent();
             if($(parentObj).hasClass('row')) {
                 var pageObj = $('#' + $(dateListLabel.table().container()).attr('id') + ' .dataTables_paginate.paging_full').detach();
                 parentObj.html('').append(pageObj);
             }*/

            dateListLabel.on( 'select', settings.event.select);
            dateListLabel.on( 'deselect', settings.event.deselect);
            $t.getGlobalId = dateListLabel;
        });
    };
//Datatable Call Function Only List Date
    $.fn.dtbd = function(options) {
        const defaults = {
            destroy: true,
            responsive: true,
            retrieve: false,
            processing: true,
            serverSide: true,
            ajax: {
                url: null,
                //dataSrc:'records',
                type: 'POST',
                data: null
            },
            columns: null,
            // columnDefs:null,
            columnDefs: [
                {
                    targets: [0],
                    visible: false,
                    ordering: false
                },
                {
                    targets: 1,
                    render: function (data) {
                        return $.DLN.fn.dfv(data);
                    },
                }
            ],
            pagingType: 'full',
            order: [[1, 'desc']],
            paging: true,
            info: false,
            pageLength: 15,
            scrollY: '439px',
            searching: false,
            select: 'single',
            lengthChange: false,
            language: {
                //url: 'lib/plugins/dataTables-1.10.12/i18n/' + $.DLN.loginManager.getLang() + '.lang',
                oPaginate: {
                    sFirst: '&#171;',
                    sLast: '&#187;',
                    sNext: '&#155;',
                    sPrevious: '&#139;'
                }
            },
            event: {
                select: null,//function ( e, dt, type, indexes ){if ( type === 'row' ) {var data = dateListLabel.rows( indexes ).data()[0]}}
                deselect: null//function ( e, dt, type, indexes ){if ( type === 'row' ) {var data = dateListLabel.rows( indexes ).data()[0]}}
            }
        };
        const settings = $.extend(true, defaults, options);
        $.fn.dataTable.ext.errMode = 'none';
        this.getGlobalId = null;
        const $t = this;
        return this.each(function(i,e) {
            const dateListLabel = $(e).DataTable(settings);

            const complexSel = $('#' + $(dateListLabel.table().container()).attr('id') + ' .dataTables_paginate.paging_full');
            const parentObj = complexSel.parent().parent();
            if($(parentObj).hasClass('row')) {
                const pageObj = complexSel.detach();
                parentObj.html('').append(pageObj);
            }

            dateListLabel.on( 'select', settings.event.select);
            dateListLabel.on( 'deselect', settings.event.deselect);
            // console.log(dateListLabel);
            $t.getGlobalId = dateListLabel;
            //return dateListLabel;
        });

    };
//DateTimePicker Call Function
    $.fn.dtp = function(options) {
        return this.each(function (i, e) {
            let settings;
            let cultureInfo = 'en';
            if(typeof ($.DLN.loginManager ) !== 'undefined') {
                cultureInfo = $.DLN.loginManager.getLangCultureInfo();
            }


            moment.locale(cultureInfo);
            if($(e).hasClass('date-time')){
                settings = $.extend(true, options, {
                    format: moment.localeData().longDateFormat('L') + ' ' + moment.localeData().longDateFormat('LTS'),//'YYYY-MM-DD HH:mm:ss',
                    locale: moment.locale(),
                    showClose:true
                } );
                $(e).datetimepicker(settings);
            }else {
                settings = $.extend(true, options, {
                    format: moment.localeData().longDateFormat('L'),
                    locale: moment.locale()
                });
                $(e).datetimepicker(settings);
            }

            // console.log($(e).find('input'));

            /* $(e).find('input').change(function () {
                 console.log('hnage');

                 $(e).data('DateTimePicker').date($(this).val());
             });
             $(e).find('input').change();*/
            /*
            $(e).on('dp.show', function () {
                $(e).data('DateTimePicker').date($(e).find('input').val());
            });*/
        })
    }
//JQGrid Call Function
    $.fn.jqg = function(options) {
        const defaults = {
            url: null,
            mtype: 'POST',
            datatype: 'json',
            viewrecords: true,
            // width: 750,
            height: 308,
            rowNum: 10,
            rowList: [10, 20, 30, 40, 50],
            ondblClickRow: null,
            serializeGridData: null,
            subGridRowExpanded: null,
            subGrid: null,
            gridComplete: null,
            //emptyrecords: 'Kayıt Yok',
            scroll: false,
            fluidHeight: false,
            fluidWidth: true,
            search: false,
            /*autowidth:false,
            shrinkToFit:false*/
        };

        const functions = {
            resize: function () {
                $(window).trigger("resize");
            },
            changejQGridDesign: function (table, pager, fluidHeight, fluidWidth) {
                fluidWidth = (typeof fluidWidth !== 'undefined') ? fluidWidth : true;
                fluidHeight = (typeof fluidHeight !== 'undefined') ? fluidHeight : false;


                //replace icons with FontAwesome icons like above
                //updatePagerIcons
                const replacement =
                    {
                        'ui-icon-seek-first': 'ace-icon fa fa-angle-double-left bigger-140',
                        'ui-icon-seek-prev': 'ace-icon fa fa-angle-left bigger-140',
                        'ui-icon-seek-next': 'ace-icon fa fa-angle-right bigger-140',
                        'ui-icon-seek-end': 'ace-icon fa fa-angle-double-right bigger-140',
                        'ui-icon-search': 'ace-icon fas fa-search',
                        'ui-icon-refresh': 'ace-icon fas fa-sync'
                    };
                /*$('.ui-pg-table:not(.navtable) > tbody > tr .ui-icon').each(function () {
                    console.log($(this));
                })*/
                $('.ui-pg-table:not(.navtable) > tbody > tr .ui-icon').each(function () {
                    // console.log($(this));

                    const icon = $(this);
                    const $class = $.trim(icon.attr('class').replace('ui-icon', ''));

                    if ($class in replacement) icon.attr('class', 'ui-icon ' + replacement[$class]);
                });

                // enableTooltips
                $('.navtable .ui-pg-button').tooltip({container: 'body'});
                $(table).find('.ui-pg-div').tooltip({container: 'body'});

                const $grid = $(table),
                    newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                    newHeight = $grid.closest(".ui-jqgrid").parent().height();
                const pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                const headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                if (fluidWidth)
                    $grid.jqGrid("setGridWidth", newWidth, true);
                if (fluidHeight)
                    $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
                let resizeTO;


                $(window).on("resize", function () {
                    if (resizeTO) clearTimeout(resizeTO);
                    resizeTO = setTimeout(function () {
                        const $grid = $(table),
                            newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                            newHeight = $grid.closest(".ui-jqgrid").parent().height();
                        const pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                        const headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                        if (fluidWidth)
                            $grid.jqGrid("setGridWidth", newWidth, true);
                        if (fluidHeight)
                            $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
                    }, 300);
                });

            }
        };
        const settings = $.extend(true, defaults, options);
        return this.each(function(i,e) {


            if ( functions[options] ) {
                return functions[ options ].apply( this, Array.prototype.slice.call( arguments, 1 ));
            } else if ( typeof options === 'object' || ! options ) {


                if (typeof ($(e)[0].grid) == 'undefined') {
                    $(e).jqGrid(settings);
                    $(e).jqGrid('navGrid', settings.pager, {
                        edit: false,
                        add: false,
                        del: false,
                        pdf: true,
                        excel: true,
                        refresh:false,
                        search: false
                    }, {}, {}, {}, {multipleSearch: false, multipleGroup: true});

                } else {
                    $(e).trigger('reloadGrid');
                }
                functions.changejQGridDesign(e, settings.pager, settings.fluidHeight, settings.fluidWidth)
            }
        });

    };
//Noty Prompt Call Function
    $.fn.nty = function(options) {
        const defaults = {
            type: 'error',
            text: 'Confirm',
            layout: 'center',
            modal: true,
            closeWith: null,
            /*animation: {
                open: {height: 'toggle'},
                close: {height: 'toggle'},
                easing: 'swing',
                speed: 0
            },*/
            callback: {
                afterShow: function () {
                    $('.noty_buttons button:last-child').focus();

                }
            },
            buttons: [
                {
                    addClass: 'btn btn-primary',
                    text: 'Yes',
                    onClick: function ($noty) {

                        $noty.close();
                    }
                },
                {
                    addClass: 'btn btn-danger',
                    text: 'No',
                    onClick: function ($noty) {
                        $noty.close();
                    }
                }
            ]
        };
        const settings = $.extend(true, defaults, options);
        return noty(settings);
    };

    $.fn.ntyp = function(options) {
        const defaults = {
            type: 'error',//alert, notification, warning, error, information, success
            text: 'Confirm',
            layout: 'center',
            modal: true,
            closeWith: null,
            /*animation: {
                open: {height: 'toggle'},
                close: {height: 'toggle'},
                easing: 'swing',
                speed: 0
            },*/
            callback: {
                afterShow: function () {
                    $('.noty_buttons button:last-child').focus();

                }
            },
            buttons: [
                {
                    addClass: 'btn btn-sm btn-success',
                    text: 'no',
                    onClick: function ($noty) {
                        $noty.close();
                    }
                }
            ]
        };
        const settings = $.extend(true, defaults, options);
        return noty(settings);
    };

    $.fn.ntyi = function(options) {
        const defaults = {
            type: 'information',
            text: 'Message',
            layout: 'center',
            timeout: 4000,
            progressBar: true
        };
        const settings = $.extend(true, defaults, options);
        return noty(settings);
    };
//BlockUI Call Function
    $.fn.loading = function(options) {
        const defaults = {
            css: {
                border: 'none',
                padding: '15px',
                backgroundColor: '#000',
                '-webkit-border-radius': '10px',
                '-moz-border-radius': '10px',
                'border-radius': '10px',
                opacity: .5,
                color: '#fff',
                cursor: 'default',
                'z-index': 9000000,
                overflow: 'hidden',
                'text-overflow': 'ellipsis'
            },
            message: 'Loading...',
        };
        const settings = $.extend(true, defaults, options, {message: $.DLN.LangManager.data.lang['LOADING']});
        return this.each(function (i, e) {


            $(e).block(settings);

        })
    };
    $.fn.blk = function(options) {
        const defaults = {
            css: {
                border: 'none',
                padding: '15px',
                backgroundColor: '#000',
                '-webkit-border-radius': '10px',
                '-moz-border-radius': '10px',
                'border-radius': '10px',
                opacity: .5,
                color: '#fff',
                cursor: 'default',
                'z-index': 9000000,
                overflow: 'hidden',
                'text-overflow': 'ellipsis'
            },
            message: 'Loading...',
        };
        const settings = $.extend(true, defaults, options);
        return this.each(function (i, e) {
            if($(e).prop('tagName') === 'BODY'){//TODO NEW-CLASS
                $.blockUI(settings);
            }else {
                $(e).block(settings);
            }
        })
    };
    $.fn.ublk = function(options) {
        const defaults = {};
        const settings = $.extend(true, defaults, options);
        return this.each(function (i, e) {
            if($(e).prop('tagName') === 'BODY'){//TODO NEW-CLASS
                $.unblockUI();
            }else {
                $(e).unblock(settings);
            }
        })
    };
    //JQGrid make Fluid And Style Change
    // noinspection JSUnusedGlobalSymbols
function changejQGridDesign(table, pager, fluidHeight, fluidWidth) {
        fluidWidth = (typeof fluidWidth !== 'undefined') ?  fluidWidth : true;
        fluidHeight = (typeof fluidHeight !== 'undefined') ?  fluidHeight : false;

        const replacement =
            {
                'ui-icon-seek-first': 'ace-icon fa fa-angle-double-left bigger-140',
                'ui-icon-seek-prev': 'ace-icon fa fa-angle-left bigger-140',
                'ui-icon-seek-next': 'ace-icon fa fa-angle-right bigger-140',
                'ui-icon-seek-end': 'ace-icon fa fa-angle-double-right bigger-140'
            };
        $('.ui-pg-table:not(.navtable) > tbody > tr > .ui-pg-button > .ui-icon').each(function () {
            const icon = $(this);
            const $class = $.trim(icon.attr('class').replace('ui-icon', ''));

            if ($class in replacement) icon.attr('class', 'ui-icon ' + replacement[$class]);
        });

        // enableTooltips
        $('.navtable .ui-pg-button').tooltip({ container: 'body' });
        $(table).find('.ui-pg-div').tooltip({ container: 'body' });

        const $grid = $(table),
            newWidth = $grid.closest(".ui-jqgrid").parent().width(),
            newHeight = $grid.closest(".ui-jqgrid").parent().height();
        const pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
        const headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
        if(fluidWidth)
            $grid.jqGrid("setGridWidth", newWidth, true);
        if(fluidHeight)
            $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
        let resizeTO;
        $(window).on("resize", function () {

            if(resizeTO) clearTimeout(resizeTO);
            resizeTO = setTimeout(function() {
                const $grid = $(table),
                    newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                    newHeight = $grid.closest(".ui-jqgrid").parent().height();
                const pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                const headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                if(fluidWidth)
                    $grid.jqGrid("setGridWidth", newWidth, true);
                if(fluidHeight)
                    $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
            }, 100);

        });
        $('.content-wrapper').resize(function(){
            console.log('ben alman resiser');

            if(resizeTO) clearTimeout(resizeTO);
            resizeTO = setTimeout(function() {
                const $grid = $(table),
                    newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                    newHeight = $grid.closest(".ui-jqgrid").parent().height();
                const pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                const headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                if(fluidWidth)
                    $grid.jqGrid("setGridWidth", newWidth, true);
                if(fluidHeight)
                    $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
            }, 300);
        });

    }
    //Treview

    const TreeView = function (elem, options) {
        this.elem = elem;
        this.$elem = $(elem);
        this.options = options;
        this.metadata = this.$elem.data('plugin-options');
    };
    TreeView.prototype = {
        defaults : {
            ajax: {
                url:null,
                type:'POST',
                data: null
            },
            sortable:true
        },
        draggingEl:null,
        oldParent:null,
        newParent:null,
        currentEl:null,
        selectedEl:null,
        getSelectedEl: function () {
            const $this = this;
            return $this.selectedEl;
        },
        // onClickItem:null,
        placeHolder : $('<li/>', {class:'placeholder'}),
        initTree: function () {
            const $this = this;
            $this.$elem.on('moved', function (e, o, n, el) {
                // console.log(e);
                // console.log(o);
                // console.log(n);
                // console.log(el);
            });
            $this.currentEl = this.$elem;
            $this.currentEl.find('li>span').off('click').on('click', function () {
                $this.$elem.find('.selected-el').removeClass('selected-el');
                $(this).addClass('selected-el')
                $this.selectedEl =  $(this).closest('li');

                $this.$elem.trigger('onClickItem',$this.selectedEl);
            });
        },
        initClick: function () {
            const $this = this;
            $this.currentEl.find('ul').find('li:has(ul)').addClass('parent_li');
            $this.currentEl.find('ul').find('li.parent_li > span > i.has-sub-menu').on('click', function (e) {
                const children = $(this).parent().parent('li.parent_li').find(' > ul > li');
                $this.currentEl = $(this).parent().parent();

                if (children.is(":visible")) {
                    children.hide('fast');
                    $(this).addClass('icon-plus-sign').removeClass('icon-minus-sign');
                } else {
                    const elementId = $($this.currentEl).attr('data-id');
                    $this.loadAjax(elementId);
                    $(this).addClass('icon-minus-sign').removeClass('icon-plus-sign');
                }
                e.stopPropagation();
            });
        },
        setSortableDroppable: function () {

            const $this = this;
            this.currentEl.find('ul').sortable({
                connectWith: 'li, ul',
                placeholder: 's-sortable-placeholder',
                start: function (e, ui) {
                    ui.item.show().addClass('s-sortable-helper');
                },
                stop: function (event, ui) {
                    ui.item.removeClass('s-sortable-helper');

                },
                opacity: 0.5,
                forceHelperSize: true,
                forcePlaceholderSize: true,
                change: function( event, ui ) {

                },
                update: function (event, ui) {

                    $this.draggingEl = ui.item;

                    if(ui.item != null) {
                        $this.newParent = ui.item.parent().closest('li');
                    }
                    if(ui.sender != null) {
                        $this.oldParent = ui.sender.parent().closest('li');
                        $($this.$elem).trigger('moved',[$this.draggingEl,$this.newParent,$this.oldParent]);


                    }
                    $this.reInitIcon();
                }
            });

        },
        loadAjax: function (currentElId, draggingEl) {
            if(typeof draggingEl === 'undefined') {
                draggingEl = null;
            }
            const $this = this;
            if($this.currentEl.hasClass('loaded') || this.config.ajax.url == null) {
                const children = $this.currentEl.find(' > ul > li');
                children.show('fast');
                $this.currentEl.find('>span>i').addClass('icon-minus-sign').removeClass('icon-plus-sign');

            }else{
                $.ajaxq('widget',
                    {
                        url: this.config.ajax.url,
                        type: this.config.ajax.type,
                        dataType: "json",
                        data: $.extend(
                            true,
                            {
                                SELECTED_ID: currentElId
                            },
                            this.config.ajax.data
                        ),
                        beforeSend: function () {
                            $($this.$elem).blk();
                        },
                        success: function (result) {
                            const recs = result['records'];
                            if (recs.length > 0) {
                                // noinspection HtmlUnknownAttribute,JSStringConcatenationToES6Template
                                const tmplData = '{{#each this}}' +
                                    '<li data-id="{{' + $this.config.field.ID + '}}">' +
                                    '   <span class="row-container">' +
                                    '       <i class="{{#xif " this.HAS_SUB_MENU==\'0\'"}}icon-leaf{{/xif}}{{#xif "this.HAS_SUB_MENU !=\'0\'"}}icon-plus-sign has-sub-menu{{/xif}}"></i> ' +
                                    '       <span class="{{#xif " this.' + $this.config.field.RECORD_FL + '===\'0\'"}} li-status-del {{else}} {{#xif "this.' + $this.config.field.ACTIVE_FL + '===\'N\'"}}li-status-disable {{/xif}} {{/xif}}">{{' + $this.config.field.TEXT + '}}</span>' +
                                    '   </span>' +
                                    '   <ul class="droppable"></ul>' +
                                    '</li>' +
                                    '{{/each}}';
                                const tmplDataHB = Handlebars.compile(tmplData);
                                const tmplDataHTML = tmplDataHB(recs);

                                $($this.currentEl).addClass('loaded').find('>ul').html(tmplDataHTML);
                                if (draggingEl != null) {
                                    $($this.currentEl).find('>ul').append(draggingEl);
                                }
                                $this.currentEl.find('li>span.row-container').off('click').on('click', function () {
                                    $this.$elem.find('.selected-el').removeClass('selected-el')
                                    $(this).addClass('selected-el')
                                    $this.selectedEl =  $(this).closest('li');
                                    $this.$elem.trigger('onClickItem',$this.selectedEl);
                                });
                                $this.initClick();
                                $this.setSortableDroppable();
                            }
                        },
                        complete: function () {
                            $($this.$elem).ublk();
                        }
                    }
                );
            }
        },
        init: function() {

            const $this = this;
            $this.config = $.extend(true, $this.config, $this.defaults);
            $this.config = $.extend(true, $this.config, $this.options);
            $this.config = $.extend(true, $this.config, $this.metadata);
            if($this.config.ajax.url != null){
                $this.initTree();
                $this.loadAjax(null);
            }else{
                $this.initTree();
                $this.initClick();
                $this.setSortableDroppable();
            }
            return this;
        },
        reInitIcon: function () {
            const $this = this;
            if($this.oldParent != null){
                if ($this.newParent.find('>span.row-container>i').hasClass('icon-plus-sign')) {
                    const draggingEl = $this.draggingEl;
                    $this.currentEl = $this.newParent;
                    const elementId = $this.newParent.attr('data-id');
                    $this.loadAjax(elementId,draggingEl);
                }

                if($this.oldParent.find('>ul > li').length === 0){//TODO NEW-CLASS
                    $this.oldParent.find('>span.row-container>i').removeClass('icon-plus-sign').removeClass('has-sub-menu').removeClass('icon-minus-sign').addClass('icon-leaf').off('click');
                }
                if($this.newParent.find('>ul > li').length > 0){
                    $this.newParent.find('>span.row-container>i').removeClass('icon-plus-sign').removeClass('icon-leaf').addClass('icon-minus-sign').addClass('has-sub-menu');
                    $this.newParent.find('>span.row-container>i').off('click');
                    $this.newParent.find('>span.row-container>i').on('click', function (e) {
                        const children = $(this).parent().parent('li.parent_li').find(' > ul > li');
                        $this.currentEl = $(this).parent().parent();

                        if (children.is(":visible")) {
                            children.hide('fast');
                            $(this).addClass('icon-plus-sign').removeClass('icon-minus-sign');
                        } else {
                            const elementId = $($this.currentEl).attr('data-id');
                            $this.loadAjax(elementId);
                            $(this).addClass('icon-minus-sign').removeClass('icon-plus-sign');
                        }
                        e.stopPropagation();
                    });
                    if($this.newParent.find('>ul > li').length === 1){//TODO NEW-CLASS
                        $this.newParent.addClass('loaded');
                    }
                }
            }
        },
        reloadParent: function () {
            const pEl = $(this.selectedEl).parent().parent();
            this.currentEl = pEl;
            const icon = pEl.find('> span.row-container > i.has-sub-menu');
            const elementId = $(pEl).attr('data-id');
            const children = $(pEl).find('> ul > li');

            pEl.removeClass('loaded');
            if (children.is(":visible")) {
                children.hide('fast');
                icon.addClass('icon-plus-sign').removeClass('icon-minus-sign');
            }else{

            }
            icon.addClass('icon-minus-sign').removeClass('icon-plus-sign');
            this.loadAjax(elementId);
        }
    }
    TreeView.defaults = TreeView.prototype.defaults;
    $.fn.treeView = function ( options ) {
        this.config = $.extend(true, this.defaults, this.options, this.metadata);
        const $this = this;
        return this.each(function () {
            $this.obj = new TreeView(this, options).init();

        });
    }
//End Treview
// })( jQuery );


$.DLN.fn = {
    dfv: function(val, inFormat,  outFormat= null){
        if(outFormat == null){
            outFormat = 'L';
        }
        // else {
        //     if (inFormat == null) {
        //         if (val != null && val.length > 10) {
        //             inFormat = 'YYYY-MM-DD HH:mm:ss';
        //             outFormat = 'L LTS';
        //         } else {
        //             inFormat = 'YYYY-MM-DD';
        //         }
        //     }
        // }
        if(!moment(val, inFormat).isValid()){
            return null;
        }
        const d = moment(val, inFormat);
        return d.format(outFormat);
    },
    dffv: function(val, inFormat,  outFormat= null){
        if(outFormat == null){
            outFormat = 'L';
        }
        else {
            if (inFormat == null) {
                if (val != null && val.length > 10) {
                    if(/(.*[0-9]{7}$)/.exec(val)){
                        inFormat = 'HH:mm:ss'
                    }else {
                        inFormat = 'YYYY-MM-DD HH:mm:ss';
                    }
                    /*if (outFormat == null) {
                        outFormat = 'L LTS';
                    }*/
                }else if(val != null && val.length === 8){
                    if(/[0-9]{1,2}:[0-9]{1,2}/.exec(val)){
                        inFormat = 'HH:mm:ss'
                    }
                } else {
                    inFormat = 'YYYY-MM-DD';
                }
            }
        }
        if(!moment(val, inFormat).isValid()){
            return null;
        }
        const d = moment(val, inFormat);
        return d.format(outFormat);
    },
    dftv: function(val, inFormat,  outFormat= null){
        if(outFormat == null){
            outFormat = 'L LTS';
        }
        else {
            if (inFormat == null) {
                if (val != null && val.length > 10) {
                    inFormat = 'YYYY-MM-DD HH:mm:ss';
                    /*if (outFormat == null) {
                        outFormat = 'L LTS';
                    }*/
                } else {
                    inFormat = 'YYYY-MM-DD';
                }
            }
        }
        if(!moment(val, inFormat).isValid()){
            return null;
        }
        const d = moment(val, inFormat);
        return d.format(outFormat);
    },
    getLastDateOfMonth: function (val, format){
        if(format == null){
            format ='DD.MM.YYYY';
        }
        if(!moment(val, format).isValid()){
            return null;
        }
        const d = moment(val, format);
        d.add(1,'months').date(0);
        return d.format('L');
    },
    getFirstDateOfMonth: function (val, format){
        if(format == null){
            format ='DD.MM.YYYY';
        }
        if(!moment(val, format).isValid()){
            return null;
        }
        const d = moment(val, format);
        d.date(1);
        return d.format('L');
    },
    resize: function () {
        setTimeout(function () {
            $(window).resize();

        },5000)
    }
}
$.fn.extend({
    disable: function(state) {
        return this.each(function() {
            this.disabled = state;
        });
    }
});
$.fn.clearValidation = function(){$(this).each(function(){$(this).find('.form-group.has-error').removeClass('has-error');$(this).find('span.help-block.has-error').remove();})};

function setModalMaxHeight(element) {
    const $this = this;
    $this.$element     = $(element);
    $this.$content     = $this.$element.find('.modal-content');
    const borderWidth = $this.$content.outerHeight() - this.$content.innerHeight();
    const dialogMargin = $(window).width() < 768 ? 20 : 60;
    const contentHeight = $(window).height() - (dialogMargin + borderWidth);
    const headerHeight = $this.$element.find('.modal-header').outerHeight() || 0;
    const footerHeight = $this.$element.find('.modal-footer').outerHeight() || 0;
    const maxHeight = contentHeight - (headerHeight + footerHeight);

    this.$content.css({
        'overflow': 'hidden'
    });

    this.$element
        .find('.modal-body').css({
        'max-height': maxHeight,
        'overflow-y': 'auto'
    });

    $($this.$element).on('show.bs.modal', function() {
        $($this.$element).show();
        setModalMaxHeight($this.$element);
    });

    $(window).resize(function() {
        if ($($this.$element).length !== 0) {//TODO NEW-CLASS
            setModalMaxHeight($($this.$element));
        }
    });
}

function dateTimeFormatterJQG(cellvalue) {
    if(cellvalue === null){
        return '';
    }
    return $.DLN.fn.dfv(cellvalue);
}
function timeFormatterJQG(cellvalue) {
    if(cellvalue === null){
        return '';
    }
    return $.DLN.fn.dftv(cellvalue);
}
function justTimeFormatterJQG(cellvalue) {
    if(cellvalue === null){
        return '';
    }
    return $.DLN.fn.dftv(cellvalue,'LT','LT');
}
