/**
 * Created by ferhat on 20.02.2017.
 */

var proxyPath = '../../lib/php/jsonp.php?';
var $window = window;
var workInFrame = false;
var clientEvent = {
    select:'patientSelect',//'selectClient.client',
    main:'patientSelect'//'.client'
}
if(window != null) {
    if (window.top != null) {
        workInFrame = window == window.top ? false : true;
    }
}

//Language Manager
function getDLN() {
    if(typeof ($.DLN ) == 'undefined'){
        if(window.parent != window){
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
                userInfo: '.user-info'

            }
        },
        checkLoginTimer: function (obj, callback) {
            var $this = null;

            if (typeof (obj) === 'undefined' || obj === null) {
                $this = this;
            } else {
                $this = obj;
            }
            //console.log($this)
            var o = $this.options;
            var modulePath = 'app=loginUser@';
            $.ajaxq('widget',
                {
                    url: proxyPath + modulePath + 'checkLoginTimer',
                    contentType: 'application/x-www-form-urlencoded',
                    type: 'POST',
                    dataType: 'json',
                    success: function (result, textStatus, xhr) {
                        if (result['resCode'] == 0) {
                            var session = result['s'];
                            if (session['sTimeout'] == true) {
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

                        }
                        $this.startLoginDetect();
                        if (callback) {
                            callback();
                        }
                    },
                    error: function (xhr, textStatus, errorThrown) {

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
            var $this = this;
            var o = $this.options;

            $this.stopLoginDetect();
            o.timeOutCont.push(setTimeout($this.checkLoginTimer, o.timeOutReDetect * 1000 * 60, $this));
        },
        stopLoginDetect: function () {
            var $this = this;
            var o = $this.options;
            for (var i = 0; i < o.timeOutCont.length; i++) {
                clearTimeout(o.timeOutCont[i]);
            }
            o.timeOutCont = [];
        },
        getLang: function () {
            var $this = this;
            var o = this.options;
            return o.loggedData.lang;
        },
        getLangCultureInfo: function () {
            var $this = this;
            var o = this.options;
            return o.loggedData.langCultureInfo;
        },
        showLoginScreen: function () {
            $('.frame-block').blk({message: 'Please Login'});
        },
        checkLogin : function () {
            // console.log('false checklogin');

        },


        setLangCultureInfo: function (val) {
            var $this = this;
            var o = $this.options;
            var s = o.selector;
            if(o.loggedData.langCultureInfo != val) {
                // moment.locale(val);
                o.loggedData.langCultureInfo = val;
                $('body').trigger('langCultureInfoChanged');
            }
        },
        setLang: function (val) {
            var $this = this;
            var o = $this.options;
            var s = o.selector;
            if(o.loggedData.lang != val) {
                o.loggedData.lang = val;
                $.DLN.LangManager.loadGeneralResource();
                $('body').trigger('langChanged');
            }
        },
    }
}
if (typeof ($.DLN.iFrameClickManager ) === 'undefined') {
    $.DLN.iFrameClickManager = {
        options : {
            isOverIFrame:false,
            selector: '.control-sidebar'
        },
        processMouseOut : function() {

            $.DLN.iFrameClickManager.options.isOverIFrame = false;
            top.focus();
        },

        processMouseOver : function() {
            $.DLN.iFrameClickManager.options.isOverIFrame = true;
        },

        processIFrameClick : function() {
            var o  = $.DLN.iFrameClickManager.options;
            if(o.isOverIFrame) {
                if($(o.selector).hasClass('control-sidebar-open')) {
                    $(o.selector).removeClass('control-sidebar-open');
                }
            }
        },

        attachOnloadEvent : function(func, obj) {
            if(typeof window.addEventListener != 'undefined') {
                window.addEventListener('load', func, false);
            } else if (typeof document.addEventListener != 'undefined') {
                document.addEventListener('load', func, false);
            } else if (typeof window.attachEvent != 'undefined') {
                window.attachEvent('onload', func);
            } else {
                if (typeof window.onload == 'function') {
                    var oldonload = onload;
                    window.onload = function() {
                        oldonload();
                        func();
                    };
                } else {
                    window.onload = func;
                }
            }

        },

        init : function() {
            var element = document.getElementsByTagName('iframe');
            for (var i=0; i<element.length; i++) {
                element[i].onmouseover = this.processMouseOver;
                element[i].onmouseout = this.processMouseOut;
            }
            if (typeof window.attachEvent != 'undefined') {

                top.attachEvent('onblur', this.processIFrameClick);
            }
            else if (typeof window.addEventListener != 'undefined') {
                top.addEventListener('blur', this.processIFrameClick, false);
            }
            $('.main-sidebar').click(function () {
                if($('.control-sidebar').is(':visible'))
                    $('.control-sidebar').removeClass('control-sidebar-open');
            });
            $('.content-wrapper').click(function () {
                if($('.control-sidebar').is(':visible'))
                    $('.control-sidebar').removeClass('control-sidebar-open');
            });
            $('.main-header').click(function (e) {
                if($(e.target).attr('id') != 'mainMenuToggle') {
                    if ($('.control-sidebar').is(':visible'))
                        $('.control-sidebar').removeClass('control-sidebar-open');
                }
            });
        }
    }
}
$( document ).ajaxSuccess(function( event, xhr, settings ) {
    var result = null;
    try{
        result = $.parseJSON(xhr.responseText );
    }catch(err){
    }
    var $lm = null;
    $lm = $.DLN.loginManager;
    try{
        if(result){
            if(result['s']){
                var session = result['s'];
                if(session == null){
                    $lm.checkLogin();
                }
                // o.loggedData.lang = session['lang'];
                $.DLN.loginManager.setLang(session['lang']);
                // o.loggedData.langCultureInfo = session['langCultureInfo'];
                $.DLN.loginManager.setLangCultureInfo(session['langCultureInfo']);

                if(session['companyId'] && session['userId']){
                    if($lm.options.loggedData.companyId != session['companyId'] || $lm.options.loggedData.userId != session['userId']){
                        $lm.checkLogin();
                    }
                }
                if(session['sTimeout'] == true){
                    $lm.showLoginScreen();
                }else{
                    $lm.options.timeOutReDetect = session['sTimeoutReDetect'];
                    $lm.startLoginDetect();
                }
            }else{
                //$lm.checkLogin();
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

function getClientId() {
    /*if(workInFrame){
        $.DLNApp.patientManager.data.hastaId;
    }else {
        $.DLNApp.patientManager.data.hastaId;
    }*/
    if(typeof ($.DLN.patientManager ) !== 'undefined') {
        return $.DLN.patientManager.event.data.id;
    }else{


        if(parent.window != window)
            return parent.window.getPatientId();
        else
            return null;
    }
}

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
        var $this = this;
        var o = this.options;
        $this.initLangValue();
        var lang = null;
        if (typeof ($.DLN.loginManager ) == 'undefined') {
            lang = 'tr';
        } else {
            lang = $.DLN.loginManager.getLang();
        }
        $.ajaxq('lang',
            {
                url: proxyPath + 'app=loginUser@' + 'loadResourceFile',
                data:{resourceFile:'../../lib/app/resources/' + lang + '/general.json'},
                type: "POST",
                dataType: "json",
                success: function (result, textStatus, xhr) {
                    $.DLN.options.LM.gnLang = result;
                    $.DLN.options.LM.lang = $.extend(true,$.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                    // $('body').trigger('langLoadedGeneral');
                },
                complete: function (xhr, textStatus) {
                    $('body').trigger('langLoadedGeneral.lang');
                }
            }
        );
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

        var workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                workInFrame = window == window.top ? false : true;
            }
        }
        var pathname = "";


        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
            if (typeof (pathname ) == 'undefined') {
                pathname = "/module/";
            }

        }else{
            pathname = "/module/";
        }

        var $this = this;
        var o = this.options;
        $this.initLangValue();
        var lang = null;
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
        if(workInFrame || pathname.indexOf('module') !== -1) resourcePath = '../../'+resourcePath;
        var pluginsArray = null;
        var $fig = 0;

        if($.isArray(filePath)){
            for($fi=0; $fi<filePath.length; $fi++) {
                // console.log($fi);
                // console.log(resourcePath + '/' + lang + '/' + filePath[$fi]);
                try{


                    $.ajaxq('lang',
                        {
                            url: proxyPath + 'app=loginUser@' + 'loadResourceFile',

                            data:{resourceFile: resourcePath + '/' + lang + '/' + filePath[$fi]},
                            type: "POST",
                            dataType: "json",
                            success: function (result, textStatus, xhr) {


                                $.DLN.options.LM.lcLang = result;
                                $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.lang, $.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);


                                $.DLN.options.lastLoadedStatus = true;

                            },
                            complete: function (xhr, textStatus) {
                                $fig++;
                                if(filePath.length == $fig) {

                                    pluginsArray = $this.getLanguageForPluginsArray();
                                    if (pluginsArray.length == 0) {
                                        // $('body').off('langLoaded.lang');
                                        $('body').trigger('langLoaded.lang');
                                        // $this.data.loaded = true;

                                    } else {
                                        $this.loadLanguageForPlugins(pluginsArray);
                                    }
                                }
                            },
                            error: function (xhr, textStatus, errorThrown) {
                                $.DLN.options.lastLoadedStatus = false;
                                /*if((filePath.length-1) == $fig) {
                                    // $('body').off('langLoaded.lang');
                                    $('body').trigger('langLoaded.lang');
                                }*/

                            },
                            fail: function (xhr) {

                            }
                        }
                    );
                } catch(error){
                    // Log error
                }
            }
        }else{


            $.ajaxq('lang',
                {
                    url: proxyPath + 'app=loginUser@' + 'loadResourceFile',
                    data:{resourceFile: resourcePath + '/' + lang + '/' + filePath},
                    type: "POST",
                    dataType: "json",
                    success: function (result, textStatus, xhr) {
                        // $this.data.lcLang = result;
                        // $.DLN.options.LM.lcLang = result;
                        // $this.data.lang = $.extend(true, $this.data.gnLang, $this.data.lcLang);
                        // $.DLN.options.LM.lang = $.extend(true, $.DLN.options.LM.gnLang, $this.data.lcLang);

                        $.DLN.options.LM.lcLang = result;
                        $.DLN.options.LM.lang = $.extend(true,$.DLN.options.LM.lang,$.DLN.options.LM.gnLang, $.DLN.options.LM.lcLang);
                        $.DLN.options.lastLoadedStatus = true;


                    },
                    complete: function (xhr, textStatus) {

                        pluginsArray = $this.getLanguageForPluginsArray();
                        if (pluginsArray.length == 0) {
                            // $('body').off('langLoaded.lang');
                            // $('body').trigger('langLoaded.lang');
                            // $this.data.loaded = true;

                        } else {
                            // console.log('ssss');

                            $this.loadLanguageForPlugins(pluginsArray);
                        }
                    },
                    error: function (xhr, textStatus, errorThrown) {
                        $.DLN.options.lastLoadedStatus = false;
                    }
                }
            );
        }


    },
    setLanguageTemplate: function (jqElement, data) {
        var $this = this;
        var o = this.options;

        if($.DLN.options.lastLoadedStatus == false) return;
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

            var html = $(this).html();

            for (var key in data) {
                var regex = new RegExp('{{tr="'+key+'"}}', "igm");
                html = html.replace(regex, data[key]);
            }
            for (var key in data) {
                var regex = new RegExp('{{data-tr="'+key+'"}}', "igm");
                html = html.replace(regex, data[key]);
            }


            $(this).html(html);
        });
        jqElement.find('script[type="text/x-handlebars-template"]').each(function () {

            var html = $(this).html();

            for (var key in data) {
                var regex = new RegExp('{{tr="'+key+'"}}', "igm");
                html = html.replace(regex, data[key]);
            }
            for (var key in data) {
                var regex = new RegExp('{{data-tr="'+key+'"}}', "igm");
                html = html.replace(regex, data[key]);
            }


            $(this).html(html);
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


        var lang = null;
        if (typeof ($.DLN.loginManager ) == 'undefined') {
            lang = 'en';
        } else {
            lang = $.DLN.loginManager.getLang();
        }
        if(lang === null){
            lang = 'en';
        }
        var pathname = "";


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
        var prefix = '';
        var workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                workInFrame = window == window.top ? false : true;
            }
        }
        if(workInFrame || pathname.indexOf('module') !== -1) prefix='../../'
        var pluginsArray = [];

        // if (pathname.indexOf('module') !== -1) {
        pluginsArray.push(prefix+'lib/plugins/jqGrid-4.6.0/js/i18n/grid.locale-' + lang + '.js');
        pluginsArray.push(prefix+'lib/plugins/jquery-validation-1.19.1/js/localization/messages_' + lang + '.js');

        // } else {
        //     pluginsArray.push('lib/plugins/jqGrid-4.6.0/js/i18n/grid.locale-' + lang + '.js');
        // }
        return pluginsArray;
    },
    loadLanguageForPlugins: function (pluginsArray) {
        var $this = this;
        var o = this.options;

        $(pluginsArray).each(function (i, e) {
            $.ajaxq('lang', {
                url: e,
                dataType: "script",
                success: function (data, textStatus, jqxhr) {
                    //$('body').trigger('langLoaded');
                },
                complete: function (xhr, textStatus) {
                    // $('body').off('langLoaded.lang');
                    if(pluginsArray.length === (i+1)) {
                        moment.locale($.DLN.loginManager.getLangCultureInfo());
                        $('body').trigger('langLoaded.lang');
                    }
                }
            })
        });
        if(pluginsArray.length == 0){
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
                var siblings = $(e).siblings();
                var siblingHeightSum = 0;
                $(e).outerHeight(0);
                siblings.each(function (si, se) {
                    if ($(se).is(':visible') &&
                        !$(se).hasClass('without-autoheight') &&
                        // !$(se).hasClass('autoheight') &&
                        !$(se).hasClass('modal') &&
                        // !$(se).hasClass('modal-backdrop') &&
                        $(se).prop('tagName') != 'script' &&
                        $(se).prop('tagName') != 'head' &&
                        $(se).prop('tagName') != null) {
                        siblingHeightSum += $(se).outerHeight();

                    }
                })

                var content = $(e).parent();
                // console.log(e);
                // console.log($(e).parent());
                if($(e).hasClass('modal-content')){
                    siblingHeightSum = -20;
                    content = $('body');
                }
                var contentHeight = content.innerHeight() /*+ parseFloat($(e).css('margin-top'))*/;

                // console.log(contentHeight);
                var contentPadding = 0;
                if($(content).css('position') != 'fixed') {
                    contentPadding = parseFloat(content.css('padding-top')) + parseFloat(content.css('padding-bottom')) +
                        parseFloat($(e).css('margin-top')) + parseFloat($(e).css('margin-bottom'));
                }


                // console.log(contentHeight);
                // console.log(siblingHeightSum);
                // console.log(contentPadding);

                $(e).outerHeight(contentHeight - siblingHeightSum - contentPadding);
            }
        })

    },
    fixAutoHeight:function () {
        var $this = this;
        $(window, 'body').resize(function () {
            $this.resizeFixer();
        });
        if($this.resizeTO) clearTimeout($this.resizeTO);
        $this.resizeTO = setTimeout($this.resizeFixer, 100);
    },
    init:function() {//Tüm plugin nesnelerini tek bir noktadan ilk tanımlaması buradan yapılacak
        var $t = this;
        // $t.select2.init();
        $t.select2.init();
        $t.dataTable.init();
        $t.datePicker.init();
        $t.fixAutoHeight();
        $t.validation.init();
        //$(document).ajaxStart($.bui).ajaxStop($.unblockUI);
        var resizeTO = null;
        $t.doubleFixer();
        $('.modal').on('shown.bs.modal', function () {
            $(window).resize()
        })
    } ,
    doubleFixer: function (selector) {
        var $t = this;
        if (typeof ($.DLN.loginManager ) === 'undefined') {
            selector = '.double-fix';
        }else{
            selector = '.double-fix';
        }
        $(selector).each(function (index, element) {
            $(element).change(function () {
                $(this).val($t.extractNums($(this).val().replace(",", ".")));
            });
        });
    },
    extractNums: function (str) {
        var returnValue = str.match(/[-+.]?[0-9]/g);
        if (returnValue) {
            return returnValue.join("");
        }
        return returnValue;
    },
    directDoubleFix: function (element) {
        $(element).change(function () {
            $(this).val($t.extractNums($(this).val().replace(",", ".")));
        });
    }
};

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
            var o = this.options;

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
        var o = this.options;
        var opt = {}
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
        var o  = this.options;
        var $obj = $(o.selector.ajax);

        var additionalParams = {};
        if ($obj.attr('data-height')){
            additionalParams['scrollY'] = $obj.attr('data-height');
        }
        var defaults = {
            destroy: true,
            retrieve:true,
            processing:true,
            select:'single'
        }
        var settings = $.extend(true, defaults, additionalParams);
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
        var o = this.options;

        if ($(o.selector.base).length > 0) {
            $(o.selector.base).each(function () {

                var id = $(this).attr('id');
                $("#" + id).bind("invalid-form.validate", function(e,v) {
                    // console.log(a);
                    // console.log(v);
                    // console.log(c);
                    v.currentElements.each(function (i, e) {
                        if($(e).prop("tagName") === "SELECT" && $(e).next().hasClass('select2')) {
                            $(e).on('change', function (ec) {
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
                            element.next().after(error);
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
(function( $ ){

    //Select fill and Set Selecct2 Plugin
    $.fn.slb = function(options) {
        var defaults = {
            rs:null,
            event:{
                change:null,
                afterLoad:null
            },
            field:{
                text:null,
                value:null,
                disabledFL:null
            },
            beforeClear:true,
            firstNull:true,
            ajax:{
                url:null,
                postData:null,
                form:null
            },
            select2:{
                allowClear:true,
                minimumResultsForSearch:1,
                openOnEnter:true
            }
        };
        var settings = $.extend(true, defaults, options );
        return this.each(function(i,e) {
            var postData = null;
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
                $.ajaxq('lib',
                    {
                        url:settings.ajax.url,
                        contentType:"application/x-www-form-urlencoded",
                        data: postData,
                        type:"POST",
                        dataType: "json",
                        success : function( result, textStatus, xhr ) {
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
            var s = settings;

            if(s.beforeClear == true){
                $(el).empty();
            }
            if(s.firstNull == true){
                $(el).append('<option value=""></option>');
            }
            var tmplData = '{{#each this}}<option {{#xif "this.'+s.field.disabledFL+' == \'H\' || this.'+s.field.disabledFL+' == \'N\'"}} disabled {{/xif}} value="{{'+s.field.value+'}}" >{{'+s.field.text+'}}</option>{{/each}}';
            var tmplDataHB = Handlebars.compile(tmplData);
            var tmplDataHTML = tmplDataHB(s.rs);
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
        var pathname = "";
        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
        }
        var prefix = '';
        var workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                workInFrame = window == window.top ? false : true;
            }
        }
        if(workInFrame || pathname.indexOf('module') !== -1) prefix='../../';

        var defaults = {
            language: {
                url: prefix+'lib/plugins/dataTables-1.10.20/i18n/' + $.DLN.loginManager.getLang() + '.lang'
            },
            destroy: true,
            paging:false,
            pageLength:10,
            scrollY:'244px',
            searching:false,
            select:'single',
            scrollX: "100%",
            scrollCollapse: true,
            scroller: true,
            autoWidth: false
        };
        $.fn.dataTable.ext.errMode = 'none';
        this.getGlobalId = null;
        var settings = $.extend(true, defaults, options );
        var $t = this;
        return this.each(function(i,e) {
            var dateListLabel = $(e).DataTable(settings);
            $t.getGlobalId = dateListLabel;
        });
    };
//Datatable Call Function
    $.fn.dtb = function(options) {
        var pathname = "";


        if (typeof ($window.location ) !== 'undefined') {
            pathname = $window.location.pathname;
        }
        var prefix = '';
        //var workInFrame = false;
        if(window != null) {
            if (window.top != null) {
                workInFrame = window == window.top ? false : true;
            }
        }
        if(workInFrame || pathname.indexOf('module') !== -1) prefix='../../';

        /*var url = '../../lib/plugins/dataTables-1.10.12/i18n/' + $.DLN.loginManager.getLang() + '.lang';
        if(workInFrame){
            var url = 'lib/plugins/dataTables-1.10.12/i18n/' + $.DLN.loginManager.getLang() + '.lang';
        }*/
        var defaults = {
            language: {
                url: prefix+'lib/plugins/dataTables-1.10.20/i18n/' + $.DLN.loginManager.getLang() + '.json'
            },
            destroy: true,
            responsive:true,
            retrieve:false,
            processing:true,
            serverSide:true,
            ajax: {
                url:null,
                //dataSrc:'records',
                type:'POST',
                data: null
            },
            columns: null,
            columnDefs:null,
            // pagingType: 'full',
            order:[[1,'desc']],
            paging:true,
            //info:false,
            pageLength:10,
            scrollY:'244px',
            searching:false,
            select:'single',
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
        var settings = $.extend(true, defaults, options );
        this.getGlobalId = null;
        var $t = this;
        return this.each(function(i,e) {

            var dateListLabel = $(e).DataTable(settings);
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
        var defaults = {
            destroy: true,
            responsive:true,
            retrieve:false,
            processing:true,
            serverSide:true,
            ajax: {
                url:null,
                //dataSrc:'records',
                type:'POST',
                data: null
            },
            columns: null,
            // columnDefs:null,
            columnDefs:[
                {
                    targets : [0],
                    visible : false,
                    ordering:false
                },
                {
                    targets: 1,
                    render: function ( data, type, row ) {
                        return $.DLN.fn.dfv(data);
                    },
                }
            ],
            pagingType: 'full',
            order:[[1,'desc']],
            paging:true,
            info:false,
            pageLength:15,
            scrollY:'439px',
            searching:false,
            select:'single',
            lengthChange:false,
            language:{
                //url: 'lib/plugins/dataTables-1.10.12/i18n/' + $.DLN.loginManager.getLang() + '.lang',
                oPaginate:{
                    sFirst:'&#171;',
                    sLast:'&#187;',
                    sNext:'&#155;',
                    sPrevious:'&#139;'
                }
            },
            event: {
                select: null,//function ( e, dt, type, indexes ){if ( type === 'row' ) {var data = dateListLabel.rows( indexes ).data()[0]}}
                deselect: null//function ( e, dt, type, indexes ){if ( type === 'row' ) {var data = dateListLabel.rows( indexes ).data()[0]}}
            }
        };
        var settings = $.extend(true, defaults, options );
        $.fn.dataTable.ext.errMode = 'none';
        this.getGlobalId = null;
        var $t = this;
        return this.each(function(i,e) {
            var dateListLabel = $(e).DataTable(settings);

            var parentObj = $('#'+ $(dateListLabel.table().container()).attr('id') +' .dataTables_paginate.paging_full').parent().parent();
            if($(parentObj).hasClass('row')) {
                var pageObj = $('#' + $(dateListLabel.table().container()).attr('id') + ' .dataTables_paginate.paging_full').detach();
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
            var cultureInfo = 'en';
            if(typeof ($.DLN.loginManager ) !== 'undefined') {
                cultureInfo = $.DLN.loginManager.getLangCultureInfo();
            }


            moment.locale(cultureInfo);
            if($(e).hasClass('date-time')){
                var settings = $.extend(true, options, {
                    format: moment.localeData().longDateFormat('L') + ' ' + moment.localeData().longDateFormat('LTS'),//'YYYY-MM-DD HH:mm:ss',
                    locale: moment.locale(),
                    showClose:true
                } );
                $(e).datetimepicker(settings);
            }else {
                var settings = $.extend(true, options, {
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
        var defaults = {
            url:null,
            mtype: 'POST',
            datatype: 'json',
            viewrecords: true,
            // width: 750,
            height: 308,
            rowNum: 10,
            rowList:[10,20,30,40,50],
            ondblClickRow:null,
            serializeGridData:null,
            subGridRowExpanded:null,
            subGrid:null,
            gridComplete:null,
            emptyrecords:'Kayıt Yok',
            scroll:false,
            fluidHeight:false,
            fluidWidth:true,
            search:false,
            /*autowidth:false,
            shrinkToFit:false*/
        };

        var functions = {
            resize: function(){
                $(window).trigger("resize");
            },
            changejQGridDesign: function(table, pager, fluidHeight, fluidWidth)
            {
                fluidWidth = (typeof fluidWidth !== 'undefined') ? fluidWidth : true;
                fluidHeight = (typeof fluidHeight !== 'undefined') ? fluidHeight : false;


                //replace icons with FontAwesome icons like above
                //updatePagerIcons
                var replacement =
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

                    var icon = $(this);
                    var $class = $.trim(icon.attr('class').replace('ui-icon', ''));

                    if ($class in replacement) icon.attr('class', 'ui-icon ' + replacement[$class]);
                });

                // enableTooltips
                $('.navtable .ui-pg-button').tooltip({container: 'body'});
                $(table).find('.ui-pg-div').tooltip({container: 'body'});

                var $grid = $(table),
                    newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                    newHeight = $grid.closest(".ui-jqgrid").parent().height();
                var pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                var headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                if (fluidWidth)
                    $grid.jqGrid("setGridWidth", newWidth, true);
                if (fluidHeight)
                    $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
                var resizeTO;


                $(window).on("resize", function () {
                    if (resizeTO) clearTimeout(resizeTO);
                    resizeTO = setTimeout(function () {
                        var $grid = $(table),
                            newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                            newHeight = $grid.closest(".ui-jqgrid").parent().height();
                        var pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                        var headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                        if (fluidWidth)
                            $grid.jqGrid("setGridWidth", newWidth, true);
                        if (fluidHeight)
                            $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
                    }, 300);
                });

            }
        };
        var settings = $.extend(true, defaults, options );
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
        var defaults = {
            type:'error',
            text: 'Confirm',
            layout:'center',
            modal:true,
            closeWith:null,
            /*animation: {
                open: {height: 'toggle'},
                close: {height: 'toggle'},
                easing: 'swing',
                speed: 0
            },*/
            callback: {
                afterShow: function() {
                    $('.noty_buttons button:last-child').focus();

                }
            },
            buttons: [
                {
                    addClass: 'btn btn-primary',
                    text: 'Yes',
                    onClick: function($noty) {

                        $noty.close();
                    }
                },
                {
                    addClass: 'btn btn-danger',
                    text: 'No',
                    onClick: function($noty) {
                        $noty.close();
                    }
                }
            ]
        }
        var settings = $.extend(true, defaults, options );
        return noty(settings);
    };

    $.fn.ntyp = function(options) {
        var defaults = {
            type:'error',//alert, notification, warning, error, information, success
            text: 'Confirm',
            layout:'center',
            modal:true,
            closeWith:null,
            /*animation: {
                open: {height: 'toggle'},
                close: {height: 'toggle'},
                easing: 'swing',
                speed: 0
            },*/
            callback: {
                afterShow: function() {
                    $('.noty_buttons button:last-child').focus();

                }
            },
            buttons: [
                {
                    addClass: 'btn btn-sm btn-success',
                    text: 'no',
                    onClick: function($noty) {
                        $noty.close();
                    }
                }
            ]
        }
        var settings = $.extend(true, defaults, options );
        return noty(settings);
    };

    $.fn.ntyi = function(options) {
        var defaults = {
            type: 'information',
            text: 'Message',
            layout: 'center',
            timeout: 4000,
            progressBar: true
        }
        var settings = $.extend(true, defaults, options );
        return noty(settings);
    };
//BlockUI Call Function
    $.fn.loading = function(options) {
        var defaults = {
            css:{
                border: 'none',
                padding: '15px',
                backgroundColor: '#000',
                '-webkit-border-radius': '10px',
                '-moz-border-radius': '10px',
                'border-radius': '10px',
                opacity: .5,
                color: '#fff',
                cursor:'default',
                'z-index':9000000,
                overflow: 'hidden',
                'text-overflow': 'ellipsis'
            },
            message:'Loading...',
        };
        var settings = $.extend(true, defaults, options, {message:$.DLN.LangManager.data.lang['LOADING']} );
        return this.each(function (i, e) {


            $(e).block(settings);

        })
    };
    $.fn.blk = function(options) {
        var defaults = {
            css:{
                border: 'none',
                padding: '15px',
                backgroundColor: '#000',
                '-webkit-border-radius': '10px',
                '-moz-border-radius': '10px',
                'border-radius': '10px',
                opacity: .5,
                color: '#fff',
                cursor:'default',
                'z-index':9000000,
                overflow: 'hidden',
                'text-overflow': 'ellipsis'
            },
            message:'Loading...',
        };
        var settings = $.extend(true, defaults, options );
        return this.each(function (i, e) {
            if($(e).prop('tagName')=='BODY'){
                $.blockUI(settings);
            }else {
                $(e).block(settings);
            }
        })
    };
    $.fn.ublk = function(options) {
        var defaults = {};
        var settings = $.extend(true, defaults, options );
        return this.each(function (i, e) {
            if($(e).prop('tagName')=='BODY'){
                $.unblockUI();
            }else {
                $(e).unblock(settings);
            }
        })
    };
    //JQGrid make Fluid And Style Change
    function changejQGridDesign(table, pager, fluidHeight, fluidWidth) {
        fluidWidth = (typeof fluidWidth !== 'undefined') ?  fluidWidth : true;
        fluidHeight = (typeof fluidHeight !== 'undefined') ?  fluidHeight : false;


        /*jQuery(table).jqGrid('navGrid', pager, {
         edit: false, add: false, del: false,
         search: true,
         searchicon: 'ace-icon fa fa-search orange',
         refresh: true,
         refreshicon: 'ace-icon fa fa-refresh green',
         view: true,
         viewicon: 'ace-icon fa fa-search-plus grey'
         });*/
        //navButtons

        /*jQuery(table).jqGrid('inlineNav', pager,
         {  //navbar options
         edit: true,
         editicon: 'ace-icon fa fa-pencil blue',
         add: true,
         addicon: 'ace-icon fa fa-plus-circle purple',
         del: true,
         delicon: 'ace-icon fa fa-trash-o red'
         });*/

        //replace icons with FontAwesome icons like above
        //updatePagerIcons
        var replacement =
            {
                'ui-icon-seek-first': 'ace-icon fa fa-angle-double-left bigger-140',
                'ui-icon-seek-prev': 'ace-icon fa fa-angle-left bigger-140',
                'ui-icon-seek-next': 'ace-icon fa fa-angle-right bigger-140',
                'ui-icon-seek-end': 'ace-icon fa fa-angle-double-right bigger-140'
            };
        $('.ui-pg-table:not(.navtable) > tbody > tr > .ui-pg-button > .ui-icon').each(function () {
            var icon = $(this);
            var $class = $.trim(icon.attr('class').replace('ui-icon', ''));

            if ($class in replacement) icon.attr('class', 'ui-icon ' + replacement[$class]);
        });

        // enableTooltips
        $('.navtable .ui-pg-button').tooltip({ container: 'body' });
        $(table).find('.ui-pg-div').tooltip({ container: 'body' });

        var $grid = $(table),
            newWidth = $grid.closest(".ui-jqgrid").parent().width(),
            newHeight = $grid.closest(".ui-jqgrid").parent().height();
        var pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
        var headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
        if(fluidWidth)
            $grid.jqGrid("setGridWidth", newWidth, true);
        if(fluidHeight)
            $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
        var resizeTO;
        $(window).on("resize", function () {

            if(resizeTO) clearTimeout(resizeTO);
            resizeTO = setTimeout(function() {
                var $grid = $(table),
                    newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                    newHeight = $grid.closest(".ui-jqgrid").parent().height();
                var pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                var headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                if(fluidWidth)
                    $grid.jqGrid("setGridWidth", newWidth, true);
                if(fluidHeight)
                    $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
            }, 100);

        });
        $('.content-wrapper').resize(function(e){
            console.log('ben alman resiser');

            if(resizeTO) clearTimeout(resizeTO);
            resizeTO = setTimeout(function() {
                var $grid = $(table),
                    newWidth = $grid.closest(".ui-jqgrid").parent().width(),
                    newHeight = $grid.closest(".ui-jqgrid").parent().height();
                var pagerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-pager').outerHeight();
                var headerOuterHeight = $grid.closest(".ui-jqgrid").find('.ui-jqgrid-htable').outerHeight();
                if(fluidWidth)
                    $grid.jqGrid("setGridWidth", newWidth, true);
                if(fluidHeight)
                    $grid.jqGrid("setGridHeight", newHeight - pagerOuterHeight - headerOuterHeight, true);
            }, 300);
        });

    }
    //Treview

    var TreeView = function( elem, options ){
        this.elem = elem;
        this.$elem = $(elem);
        this.options = options;
        this.metadata = this.$elem.data( 'plugin-options' );
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
            var $this = this;
            return $this.selectedEl;
        },
        // onClickItem:null,
        placeHolder : $('<li/>', {class:'placeholder'}),
        initTree: function () {
            var $this = this;
            $this.$elem.on('moved', function (e, o, n, el) {
                // console.log(e);
                // console.log(o);
                // console.log(n);
                // console.log(el);
            });
            $this.currentEl = this.$elem;
            $this.currentEl.find('li>span').off('click').on('click', function (e) {
                $this.$elem.find('.selected-el').removeClass('selected-el');
                $(this).addClass('selected-el')
                $this.selectedEl =  $(this).closest('li');

                $this.$elem.trigger('onClickItem',$this.selectedEl);
            });
        },
        initClick: function (el) {

            var $this = this;
            // console.log($this.currentEl.find('li'));


            $this.currentEl.find('ul').find('li:has(ul)').addClass('parent_li');
            $this.currentEl.find('ul').find('li.parent_li > span > i.has-sub-menu').on('click', function (e) {
                var children = $(this).parent().parent('li.parent_li').find(' > ul > li');
                $this.currentEl = $(this).parent().parent();

                if (children.is(":visible")) {
                    children.hide('fast');
                    $(this).addClass('icon-plus-sign').removeClass('icon-minus-sign');
                } else {

                    var elementId = $($this.currentEl).attr('data-id');
                    $this.loadAjax(elementId);
                    $(this).addClass('icon-minus-sign').removeClass('icon-plus-sign');
                }
                e.stopPropagation();
            });
        },
        setSortableDroppable: function () {

            var $this = this;
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
            var $this = this;
            if($this.currentEl.hasClass('loaded') || this.config.ajax.url == null) {
                var children = $this.currentEl.find(' > ul > li');
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
                        beforeSend: function (xhr, settings) {
                            $($this.$elem).blk();
                        },
                        success: function (result, textStatus, xhr) {
                            var recs = result['records'];
                            var rec = null;
                            if (recs.length > 0) {



                                var tmplData = '{{#each this}}<li data-id="{{'+$this.config.field.ID+'}}"><span class="row-container"><i class="{{#xif "this.HAS_SUB_MENU==\'0\'"}}icon-leaf{{/xif}}{{#xif "this.HAS_SUB_MENU !=\'0\'"}}icon-plus-sign has-sub-menu{{/xif}}"></i> <span class="{{#xif "this.'+$this.config.field.RECORD_FL+'===\'0\'"}} li-status-del {{else}} {{#xif "this.'+$this.config.field.ACTIVE_FL+'===\'N\'"}}li-status-disable {{/xif}} {{/xif}}">{{'+$this.config.field.TEXT+'}}</span></span><ul class="droppable"></ul></li>{{/each}}';

                                var tmplDataHB = Handlebars.compile(tmplData);
                                var tmplDataHTML = tmplDataHB(recs);

                                $($this.currentEl).addClass('loaded').find('>ul').html(tmplDataHTML);
                                if (draggingEl != null) {
                                    $($this.currentEl).find('>ul').append(draggingEl);
                                }
                                $this.currentEl.find('li>span.row-container').off('click').on('click', function (e) {
                                    $this.$elem.find('.selected-el').removeClass('selected-el')
                                    $(this).addClass('selected-el')
                                    $this.selectedEl =  $(this).closest('li');
                                    $this.$elem.trigger('onClickItem',$this.selectedEl);
                                });
                                $this.initClick();
                                $this.setSortableDroppable();
                            }
                        },
                        complete: function (xhr, textStatus) {
                            $($this.$elem).ublk();
                        }
                    }
                );
            }
        },
        init: function() {

            var $this = this;
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
            var $this = this;
            if($this.oldParent != null){
                if ($this.newParent.find('>span.row-container>i').hasClass('icon-plus-sign')) {
                    var draggingEl = $this.draggingEl;
                    $this.currentEl = $this.newParent;
                    var elementId = $this.newParent.attr('data-id');
                    $this.loadAjax(elementId,draggingEl);
                }

                if($this.oldParent.find('>ul > li').length == 0){
                    $this.oldParent.find('>span.row-container>i').removeClass('icon-plus-sign').removeClass('has-sub-menu').removeClass('icon-minus-sign').addClass('icon-leaf').off('click');
                }
                if($this.newParent.find('>ul > li').length > 0){
                    $this.newParent.find('>span.row-container>i').removeClass('icon-plus-sign').removeClass('icon-leaf').addClass('icon-minus-sign').addClass('has-sub-menu');
                    $this.newParent.find('>span.row-container>i').off('click');
                    $this.newParent.find('>span.row-container>i').on('click', function (e) {
                        var children = $(this).parent().parent('li.parent_li').find(' > ul > li');
                        $this.currentEl = $(this).parent().parent();

                        if (children.is(":visible")) {
                            children.hide('fast');
                            $(this).addClass('icon-plus-sign').removeClass('icon-minus-sign');
                        } else {

                            var elementId = $($this.currentEl).attr('data-id');
                            $this.loadAjax(elementId);
                            $(this).addClass('icon-minus-sign').removeClass('icon-plus-sign');
                        }
                        e.stopPropagation();
                    });
                    if($this.newParent.find('>ul > li').length == 1){
                        $this.newParent.addClass('loaded');
                    }
                }


            }
        },
        reloadParent: function () {
            var pEl = $(this.selectedEl).parent().parent();
            this.currentEl = pEl;
            var icon = pEl.find('> span.row-container > i.has-sub-menu');
            var elementId = $(pEl).attr('data-id');
            var children = $(pEl).find('> ul > li');

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
        var $this = this;
        return this.each(function () {
            $this.obj = new TreeView(this, options).init();

        });
    }
//End Treview
})( jQuery );


$.DLN.fn = {
    dfv: function(val, format){
        var outFormat = 'L';
        if(format == null){
            if(val!=null && val.length>10) {
                format = 'YYYY-MM-DD HH:mm:ss';
                outFormat = 'L LTS';
            }else{
                format = 'YYYY-MM-DD';
            }
        }
        if(!moment(val, format).isValid()){
            return null;
        }
        var d = moment(val,format);
        return d.format(outFormat);
    },
    getLastDateOfMonth: function (val, format){
        if(format == null){
            format ='DD.MM.YYYY';
        }
        if(!moment(val, format).isValid()){
            return null;
        }
        var d = moment(val,format);
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
        var d = moment(val,format);
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
$.fn.clearValidation = function(){$(this).each(function(){$(this).find('.form-group.has-error').removeClass('has-error');$(this).find('.help-block.has-error').remove();})};

function setModalMaxHeight(element) {
    this.$element     = $(element);
    this.$content     = this.$element.find('.modal-content');
    var borderWidth   = this.$content.outerHeight() - this.$content.innerHeight();
    var dialogMargin  = $(window).width() < 768 ? 20 : 60;
    var contentHeight = $(window).height() - (dialogMargin + borderWidth);
    var headerHeight  = this.$element.find('.modal-header').outerHeight() || 0;
    var footerHeight  = this.$element.find('.modal-footer').outerHeight() || 0;
    var maxHeight     = contentHeight - (headerHeight + footerHeight);

    this.$content.css({
        'overflow': 'hidden'
    });

    this.$element
        .find('.modal-body').css({
        'max-height': maxHeight,
        'overflow-y': 'auto'
    });

    $(this.$element).on('show.bs.modal', function() {
        $(this.$element).show();
        setModalMaxHeight(this.$element);
    });

    $(window).resize(function() {
        if ($(this.$element).length != 0) {
            setModalMaxHeight($(this.$element));
        }
    });
}

function dateTimeFormatterJQG(cellvalue) {

    // function actSelFormatter (cellvalue, options, rowObject){
    //     return '<button type="button" class="btn btn-sm btn-xs-jqg btn-xs bg-purple btn-flat btnSel" data-id="'+options["rowId"]+'">'+$d.lang['BTN_SELECT_TEXT']+'</button>'
    // }
}