/**
 * Created by ferhat on 05.10.2020.
 */
/** DLNApp Plugins & Function
 * **/

proxyPath = './lib/php/proxy/jsonp.php?';
const isMainFL = true;
function getClientId() {
    return $.DLN.clientManager.event.data.id;
}
// noinspection JSUnusedGlobalSymbols
function showPrintPage(pageSrc,header,width, closeCallback) {
    const modalPrint = $('#modalPrintPage');
    modalPrint.modal({
        backdrop: 'static',
        keyboard: true
    });
    modalPrint.modal('show');
    $('#modalPrintPage iframe').attr('src',pageSrc);
    modalPrint.on('shown.bs.modal', function () {
        $(window).resize();
    });
    modalPrint.on('hidden.bs.modal', function () {
        document.title = 'D.SYS-PC';
        if(typeof (closeCallback) === 'function'){
            closeCallback();
            modalPrint.off('hidden.bs.modal')
        }
    });



}




$(function () {
    'use strict';
    function ClientObj(){
        this.id = null;
        this.name = null;
        this.fileNo = null;
    }
    $.DLN = getDLN();
    $.DLN.name = "mainFramework";
    $.DLN.options = {
        LM:{
            gnLang: null,
            lcLang: null,
            lang: null
        }
    };
    $.DLN.clientManager = {};

    // noinspection JSUnusedGlobalSymbols
    $.DLN.clientManager.event = {
        options:{
            selector:{
                clientContainer : '.patient-list-container',
                clientList: '.search-result-list>.search-result-row',
                // inputTop:'#mainClientSearchInputTopSel',
                // inputTopForm:'#mainClientSearchFormTop',
                selContainer: '.selected-client-container',
                selContainerClient: '.selected-client-container .client-name',
            }
        },
        data:{
            id:null,
            name:null,
            fileNo:null
        },
        lastSelectedPatient:null,
        prevData:{
            id:null,
            name:null,
            fileNo:null
        },
        init: function () {

            // const o = this.options;
            $('body').off('.client');
            $('.main-client-select-container').hide();

            //$('body').on('selectClient');

            $('.patient-prev button').off('click').on('click',this.getPrevPatient);
            $('.patient-next button').off('click').on('click',this.getNextPatient);


            $('#btnMainPrint').off('click').on('click',function () {
                const modalPrintFrame = $('#modalPrintFrame');
                modalPrintFrame.get(0).contentWindow.focus();
                modalPrintFrame.get(0).contentWindow.print();
            });
            const patientSearchInput = $('#headerPatientSearch');
            patientSearchInput.keydown(function () {
                $('.search-result-container').show();
            })
        },
        deSelectClient: function () {
            this.prevData = this.data;
            this.data = {
                id:null,
                name:null,
                fileNo:null
            }
            // const o = this.options;
            $('body').trigger('selectClient.client');

            const patientSearchInput = $('#headerPatientSearch');
            patientSearchInput.val('');
            // $('#headerPatientSearchButton').addClass('d-none');
            $('.patient-search-container .search-input.patient').removeClass('selected');
            patientSearchInput.focus();

        },
        selectClient: function (client, selectedItem = null) {
            this.prevData = this.data;
            this.data = client;
            // const o = this.options;
            const selectClient = $(selectedItem).clone();
            //console.log(selectClient)
            $('.patient-search-container').addClass('width-in')
            $('.patient-search-container .search-input.patient').addClass('selected');
            $('.custom-navbar > ul > li .patient-search-container .search-input.patient .patient-close button').addClass('active');
            //$('.patient-search-container .search-input.patient .selected-patient').html(client.fileNo + '-' +client.name).attr('data-id', client.id);
            $('.patient-search-container .search-input.patient .selected-patient').html(selectClient);


            // $(o.selector.clientContainer).find('li[class="active"]').removeClass('active');
            // $(o.selector.clientContainer).find('li[data-id="'+this.data.id+'"]').addClass('active');
            $('.search-result-container .patient-selected').removeClass('patient-selected');

            $('body').trigger('selectClient.client');
            $('.search-result-container').hide();
            if(selectedItem != null){
                selectedItem.addClass('patient-selected');
                this.lastSelectedPatient = selectedItem;
                if($(this.lastSelectedPatient).prev().length === 0){
                    $('.patient-prev button').disable(true);
                }else{
                    $('.patient-prev button').disable(false);
                }
                if($(this.lastSelectedPatient).next().length === 0){
                    $('.patient-next button').disable(true);
                }else{
                    $('.patient-next button').disable(false);
                }
            }
            // this.showSelectedClient();//TODOwil be check after when complete everything
            // $(o.selector.inputTop).val('').change();
        },
        setClick: function () {
            const $this = this;
            const o = this.options;
            $(o.selector.clientList).click(function () {
                const client = new ClientObj();
                client.id = $(this).attr('data-id');
                client.name = $(this).attr('data-name');
                client.fileNo = $(this).attr('data-fileNo');
                $this.selectClient(client,$(this));
            });
        },
        getPrevPatient: function () {
            const $this = $.DLN.clientManager.event;
            $($this.lastSelectedPatient).prev().click();
        },
        getNextPatient: function () {

            const $this = $.DLN.clientManager.event;
            $($this.lastSelectedPatient).next().click();
        },
        /*showSelectedClient: function () {
            const $this = this;
            const o = $this.options;
            $(o.selector.inputTopForm).hide();
            $(o.selector.selContainer).show();
            $(o.selector.selContainerClient).html(this.data['fileNo']+ ' ' +this.data['name']);
        },*/
        hideSelectedClient: function () {
            const $this = this;
            const o = $this.options;
            //$(o.selector.inputTop).val('').change();
            // $(o.selector.inputTopForm).show();
            $(o.selector.selContainer).hide();

        }
    };
    $.DLN.clientManager.search = {
        options:{
            data:{
                created : false,
                patientList: {
                    page: 0,
                    limit: 30,
                    loading: false,
                    totalPage: 2
                }
            },
            selector: {
                input: '#headerPatientSearch',
                button: '#headerPatientSearchButton',
                type: '',
                form: '#mainClientSearchForm',
                clientList: '.client-list',
                inputTop: '#mainClientSearchInputTopSel',
                closerClient: '.selected-client-container .closer button.btn',
                parentContiner: '.control-sidebar>.sidebar',
            }
        },
        init: function () {
            $.DLN.clientManager.event.init();
            // return;
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            if(!o.data.created) {
                $(s.parentContiner).append(s.template);
                o.data.created = true;
            }
            $this.search();
            $(s.button).click(function () {
                $('.patient-search-container').removeClass('width-in')
                $('.custom-navbar > ul > li .patient-search-container .search-input.patient .patient-close button').removeClass('active')
                // const em = $.DLN.clientManager.event;

                // if(em.data.id === null) {
                //     $this.search();
                // }else {
                $.DLN.clientManager.event.deSelectClient();
                $this.search();

                // }
            })
            $(s.input).keyup(function () {
                $this.search();

            });
            $('#ddmbClientSearchSearchGroup').parent().find('.dropdown-item').click(function () {
                $('#ddmbClientSearchSearchGroup').html($(this).html());
                $('#clientSearchSearchGroupType').val($(this).data('id'));
                $this.search();
            })

            const patientList = $this.options.data.patientList;
            $('.patient-list-container .search-result-list').scroll( function(){
                const {
                    scrollTop,
                    scrollHeight,
                    clientHeight
                } = $('.patient-list-container .search-result-list')[0];

                if (scrollTop + clientHeight >= scrollHeight - 35 && patientList.page <  patientList.totalPage && !patientList.loading) {
                    patientList.loading = true;
                    $this.loadPatientSearch();
                }
            });

        },
        /*initTopSearch: function () {
            // console.log('bu yazı var ise yazılan noktada kontrol yap');

            return;
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            $.ajaxq ('Hasta',
                {
                    url:proxyPath + 'app=../../module/client/php/clientSearch-1.0.0@' +'mainClientSearch',
                    contentType:'application/x-www-form-urlencoded',
                    data: { clientKeywords: $('#headerPatientSearch').val()},
                    type:'POST',
                    dataType: 'json',
                    beforeSend : function( xhr, settings ) {
                    },
                    success : function( result) {
                        if(result['resCode'] === 0){//TODO NEW-CLASS

                            const recs = result['records'];

                            const tmplData = $('#clientList-template').html();
                            const tmplDataHB = Handlebars.compile(tmplData);
                            const tmplDataHTML = tmplDataHB(recs);

                            $('.patient-list-container .search-result-list').html(tmplDataHTML);

                            $.DLN.clientManager.event.setClick();
                        }else{

                        }
                    },
                    error : function (xhr, textStatus, errorThrown) {
                        //$.ntyBE({text:'#9999:Bağlantı Hatası'});
                    },
                    complete : function ( xhr, textStatus ) {
                        //$.loadingPanelUI({blockElement:'#sidebar-menu'});
                    }
                }
            );

            /!*$(s.inputTop).select2({
                ajax: {
                    url: proxyPath + 'app=../../module/client/php/clientSearch-1.0.0@' +'mainClientSearch',
                    dataType: 'json',
                    delay: 250,
                    type:'post',
                    data: function (params) {
                        return {
                            clientSearch: params.term, // search term
                            page: params.page
                        };
                    },
                    processResults: function (data, params) {
                        params.page = params.page || 1;

                        const result = [];
                        for(let i=0; i< data.records.length; i++){
                            result.push(
                                {
                                    id:data.records[i]['CLIENT_ID'],
                                    text:data.records[i]['CLIENT_NAME_VIEW'],
                                    name:data.records[i]['CLIENT_NAME'],
                                    fileNo:data.records[i]['CLIENT_FILE_NO']
                                }
                            )
                        }
                        return {
                            results: result
                        };
                    },
                    cache: false
                },
                minimumInputLength: 0,
                allowClear: true,
                placeholder: 'Search Client'
            }).change(function () {
                if($(this).length > 0) {
                    if ($(this).val() > 0) {
                        $.DLN.clientManager.event.selectClient({
                            id:$(this).select2('data')[0]['id'],
                            name:$(this).select2('data')[0]['name'],
                            fileNo:$(this).select2('data')[0]['fileNo']
                        })
                    }
                }
            });*!/

            $(s.closerClient).click(function () {
                $.DLN.clientManager.event.hideSelectedClient();
                $.DLN.clientManager.event.deSelectClient();
                $(s.inputTop).select2('open');
            })
        },*/

        loadPatientSearch: function () {
            const $this = this;
            const modulePath = 'app=../../../module/client/php/clientSearch-1.0.0@';
            const patientList = $this.options.data.patientList;

            patientList.page++;
            $.ajaxq ('Client',
                {
                    url:proxyPath + modulePath +'mainClientSearch',
                    contentType:'application/x-www-form-urlencoded',
                    data: {
                        clientKeywords: $('#headerPatientSearch').val(),
                        clientSearchSearchGroupType: $('#clientSearchSearchGroupType').val(),
                        page: patientList.page,
                        limit: patientList.limit
                    },
                    type:'POST',
                    dataType: 'json',
                    beforeSend : function( ) {
                    },
                    success : function( result) {
                        if(result['resCode'] === 0){

                            const recs = result['records'];

                            const tmplData = $('#clientList-template').html();
                            const tmplDataHB = Handlebars.compile(tmplData);
                            const tmplDataHTML = tmplDataHB(recs);

                            if(patientList.page === 1) {
                                $('.patient-list-container .search-result-list').html(tmplDataHTML);
                            }else{
                                $('.patient-list-container .search-result-list').append(tmplDataHTML);
                            }

                            patientList.totalPage = result['resObject']['page'];

                            $.DLN.clientManager.event.setClick();
                        }else{

                        }
                    },
                    error : function (xhr, textStatus, errorThrown) {
                        //$.ntyBE({text:'#9999:Bağlantı Hatası'});
                    },
                    complete : function (  ) {
                        //$.loadingPanelUI({blockElement:'#sidebar-menu'});
                        patientList.loading = false;
                    }
                }
            );
        },
        search:function(){
            const $this = this;
            // const o = $this.options;
            // const s = o.selector;
            const patientList = $this.options.data.patientList;
            patientList.page = 0;

            $('.patient-list-container .search-result-list').scrollTop(0);
            const xhr = $.ajaxq.getActiveRequest('Client');
            if(xhr)
                xhr.abort(); // will abort the current request

            $this.loadPatientSearch();


            /*$.ajaxq ('Hasta',
                {
                    url:proxyPath + modulePath +'mainClientSearch',
                    contentType:'application/x-www-form-urlencoded',
                    data: $(s.form).serialize(),
                    type:'POST',
                    dataType: 'json',
                    beforeSend : function( xhr, settings ) {
                        //$.loadingPanelUI({maxTimeout:0, blockElement:'#sidebar-menu'});
                        //$(list+' > li').remove();
                    },
                    success : function( result) {
                        if(result['resCode'] === 0){//TODO NEW-CLASS

                            const recs = result['records'];

                            const tmplData = $('#clientList-template').html();
                            const tmplDataHB = Handlebars.compile(tmplData);
                            const tmplDataHTML = tmplDataHB(recs);

                            $('.client-list').html(tmplDataHTML);

                            $.DLN.clientManager.event.setClick();
                        }else{

                        }
                    },
                    error : function (xhr, textStatus, errorThrown) {
                        //$.ntyBE({text:'#9999:Bağlantı Hatası'});
                    },
                    complete : function ( xhr, textStatus ) {
                        //$.loadingPanelUI({blockElement:'#sidebar-menu'});
                    }
                }
            );*/
        }
    };

    $.DLN.loginManager = {
        options: {
            localStorageData: {
                username:null,
                personName : null,
                personSurname : null,
                KEEP_LOGGED: 0,
                lang: 'en',
                langCultureInfo: 'en'
            },
            localStore:null,
            langEvent:null,
            langEventGlobal:null,
            loginDetectError : 0,
            timeOutReDetect : 30,
            timeOutCont : [],
            loggedData : {
                userId : null,
                companyId : null,
                companies : [],
                companyDetail : {},
                companyName:null,

                isOwner:false,
                lang:null,//'en',
                langCultureInfo:null,//'en-GB',
                userInfo:'.user-info',
                personName:'',
                personSurname: ''
            },
            isCheckingLogin:false,
            appReady: false,
            selector: {
                loginButton: '#loginBtn',
                logoutButton: '#logoutBtn',
                companyList: '.company-menu',
                keepLogged:'#rememberMe',
                loginForm:'#userLoginForm',
                wrapper:'.mf-container',
                login:'.login',
                companyId:'#companyId',
                mainMenuForm:'#mainMenuForm',
                searchFormTop: '#mainClientSearchFormTop',
                selectedClientTop: '.selected-client-container-outer',
                loginUsername:'#loginUsername',
                loginPassword:'#loginPassword'

            },
            checkedLoginFL:false
        },
        init: function () {
            const $this = this;
            const o = this.options;
            const s = o.selector;
            // const loginButton = this.options.selector.loginButton;
            o.checkedLoginFL = true;

            o.localStore = window.localStorage;
            if(o.localStore.getItem('dlnFrame') !== null) {
                o.localStorageData = JSON.parse(o.localStore.getItem('dlnFrame'));

                $this.setLang(o.localStorageData.lang);
                $this.setLangCultureInfo(o.localStorageData.langCultureInfo);
            }


            // o.localStore.getItem('dlnFrame');
            $('body').on('langLoadedGeneral.lang', function () {
                $.DLN.LangManager.setLanguageTemplate();
            });
            o.checkedLoginFL = false;
            const keepLogged =$(s.keepLogged);
            const userNameView = $('.user-name-view');
            const usernameCont = $('.user-name-cont');
            const loginUsername = $(s.loginUsername);
            const loginPassword = $(s.loginPassword);
            keepLogged.hide();
            if(o.localStorageData['KEEP_LOGGED'] === 1){
                if(typeof o.localStorageData['s']['username'] !== "undefined" ){
                    loginUsername.val(o.localStorageData['username']);
                    usernameCont.hide();
                    keepLogged.show();
                    userNameView.html(o.localStorageData['personName'] + ' ' + o.localStorageData['personSurname']);
                    loginPassword.focus();
                }
            }else{
                usernameCont.show();
                keepLogged.hide();
            }
            keepLogged.off('change');
            keepLogged.on('change',function () {
                o.localStorageData = JSON.parse(o.localStore.getItem('dlnFrame'));
                if($(s.keepLogged).is(':checked')){
                    if(typeof o.loggedData['s']['username'] !== "undefined"){
                        loginUsername.val(o.localStorageData['username']);
                        usernameCont.hide();
                        keepLogged.show();
                        userNameView.html(o.localStorageData['personName'] + ' ' + o.localStorageData['personSurname']);
                        loginPassword.focus();
                    }else{
                    }
                    o.localStorageData.KEEP_LOGGED = 1;
                }else{
                    o.localStorageData.KEEP_LOGGED = null;
                    usernameCont.show();
                    keepLogged.hide();
                    $(s.loginUsername).focus();
                }
                o.localStore.getItem('dlnFrame',o.localStorageData);

            });

            $(s.loginForm+' input').off('keyup');
            $(s.loginForm+' input').on('keyup',function (e) {
                if (e.which === 13) {
                    $this.login();
                }
            });
            $this.checkLogin();
            $(s.loginButton).click(function () {
                $this.login();
            })
            $(s.logoutButton).click(function () {
                $this.logout();
            })
            $(s.companyId).off();
            $(s.companyId).change($this.changeCompany);

            $.ajaxq ('widget',
                {
                    url: proxyPath + 'app=loginUser@' + 'getFormPath',
                    data: {
                        formName: 'change-password'
                    },
                    type: 'POST',
                    dataType: 'json',
                    success: function (result) {


                        if (result['resCode'] === 0) {
                            const rec = result['records'][0];
                            $('#changePasswordBtn').click(function () {

                                $('#mainContentFrame').attr('src', rec['formPath']+'?'+rec['formTimeStamp']);
                            });

                        }
                    }
                }
            );

            $('#userConfigBtn').hide();
        },
        login: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            const modulePath = 'app=loginUser@';
            $.ajaxq ('widget',
                {
                    url:proxyPath + modulePath +'userLogin',
                    contentType:'application/x-www-form-urlencoded',
                    data: $(s.loginForm).serialize(),
                    type:'POST',
                    dataType: 'json',
                    beforeSend : function( xhr, settings ) {
                        //$.loadingPanelUI({maxTimeout:0});
                    },
                    success : function( result) {
                        if(result['resCode'] === 0){
                            if(result['records']['firstLogin'] === "Y"){

                                $.ajaxq ('widget',
                                    {
                                        url: proxyPath + 'app=loginUser@' + 'getFormPath',
                                        data: {
                                            formName: 'change-password-first'
                                        },
                                        type: 'POST',
                                        dataType: 'json',
                                        success: function (result) {


                                            if (result['resCode'] === 0) {
                                                const rec = result['records'][0];
                                                window.location.href = window.location.origin +"/" + rec['formPath']+'?'+rec['formTimeStamp']

                                            }
                                        }
                                    }
                                );


                            }
                            const session = result['s'];
                            $this.showWrapperScreen();

                            o.loggedData.companies = result['records']['COMPANIES'];
                            o.loggedData.companyDetail = result['records']['COMPANY_DETAIL'];

                            //o.loggedData.companyId = session['companyId'];
                            //o.loggedData.userId = session['userId'];

                            $this.setCompanyId(session['companyId']);
                            $this.setCompanyName(session['companyName']);
                            $this.setIsOwner(session['isOwner']);
                            $this.setLang(session['lang']);
                            $this.setLangCultureInfo(session['langCultureInfo']);
                            $this.setUserId(session['userId']);
                            o.loggedData.personName = session['personName'];
                            o.loggedData.personSurname = session['personSurname'];
                            o.timeOutReDetect = session['sTimeoutReDetect'];

                            $this.checkLogin();
                            ////Set Cookie
                            if($(s.keepLogged).is(':checked')){
                                o.localStore.setItem('dlnFrame', JSON.stringify(o.localStorageData));
                                // $.cookie('PERSON_NAME', result['s']['personName'] + ' ' + result['s']['personSurname'], { expires : 20 });
                                // $.cookie('USERNAME', $('#loginUsername').val(), { expires : 20 });
                                // $.cookie('KEEP_LOGGED', 1, { expires : 20 });
                                // $.cookie('LANG', session['lang'], { expires : 20 });
                                // $.cookie('LANG_CULTURE_INFO', session['langCultureInfo'], { expires : 20 });
                            }
                            if(o.localStore.getItem('dlnFrame') !== null)
                            o.localStorageData = JSON.parse(o.localStore.getItem('dlnFrame'));
                            o.localStorageData.lang = session['lang'];
                            o.localStorageData.langCultureInfo = session['langCultureInfo'];

                            // $.cookie('LANG', session['lang'], { expires : 20 });
                            // $.cookie('LANG_CULTURE_INFO', session['langCultureInfo'], { expires : 20 });

                            o.localStore.setItem('dlnFrame', JSON.stringify(o.localStorageData));

                            $('#loginPassword').val('');

                        }else{
                            // const $lang = $.DLN.options.LM.lang;
                            $.fn.ntyi({
                                type:'warning',
                                text: '#'+result['resCode'] + ' : ' +result['resMsg'],
                                layout: 'bottomCenter',
                            });

                        }
                    }
                }
            );
        },
        fillCompany: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            const tmplCompany = $('#companyList-template').html();
            const tmplCompanyHB = Handlebars.compile(tmplCompany);
            const tmplCompanyHTML = tmplCompanyHB(o.loggedData.companies);
            $(s.companyList + ' ul.dropdown-menu').html(tmplCompanyHTML);
            $(s.companyList + ' a.dropdown-toggle-company span').html(o.loggedData.companyName);
            $(s.companyList + ' ul.dropdown-menu>li>a').click(function () {
                const id = $(this).attr('data-company-id');
                if($(s.companyId).val() !== id) { //TODO NEW-CLASS
                    $(s.companyId).val(id);
                    $this.changeCompany();
                }
            });

            $(s.companyList + ' ul.dropdown-menu>li>a[data-company-id="'+o.loggedData.companyId+'"]').addClass('disabled');
            if(!o.appReady) {
                $(document).trigger('appReady');
                o.appReady = true;
            }
        },
        logout: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            const modulePath = 'app=loginUser@';
            $.ajaxq ('widget',
                {
                    url:proxyPath + modulePath +'userLogout',
                    contentType:'application/x-www-form-urlencoded',
                    data: $(s.loginForm).serialize(),
                    type:'POST',
                    dataType: 'json',
                    success : function( result) {
                        if(result['resCode'] === 0){//TODO NEW-CLASS
                            $this.stopLoginDetect();
                            $this.showLoginScreen();

                            $this.setCompanyId(null);
                            $this.setCompanyName(null);
                            $this.setIsOwner(null);
                            $this.setLang(null);
                            $this.setLangCultureInfo(null);
                            $this.setUserId(null);
                            o.loggedData.personName = '';
                            o.loggedData.personSurname = '';
                        }
                    }
                }
            );
        },
        showLoginScreen: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            $('#loginPassword').attr('type', 'password')
            $(s.wrapper).hide();
            $(s.login).show();
            $this.stopLoginDetect();
        },
        showWrapperScreen: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            $('#loginPassword').attr('type', 'text')
            $(s.wrapper).show();
            $(s.login).hide();

            const modulePath = 'app=loginUser@';
            $.ajaxq ('widget',
                {
                    url: proxyPath + modulePath + 'getDashboardFormPath',
                    data: {
                        formName: 'dashboard'
                    },
                    type: 'POST',
                    dataType: 'json',
                    success: function (result) {


                        if (result['resCode'] === 0) {
                            const rec = result['records'][0];
                            const mainContentFrame = $('#mainContentFrame');
                            if(mainContentFrame.length > 0) {
                                const frame = document.getElementById("mainContentFrame");
                                const frameDoc = frame.contentDocument || frame.contentWindow.document;
                                frameDoc.documentElement.innerHTML = "";
                            }

                            const body = $('body');

                            if(rec['formType'] === 'F'){//TODO NEW-CLASS

                                if(mainContentFrame.length === 0) {
                                    const f = $('<iframe/>', {
                                        id: 'mainContentFrame',
                                        name: 'mainContentFrame',
                                        allowtransparency: 'true',
                                        class: 'main-content-frame'
                                    });
                                    $('.content-frame').append(f);
                                }

                                if(!body.hasClass('frame')) {
                                    body.addClass('frame');
                                }
                                mainContentFrame.attr('src', rec['formPath']+'?'+rec['formTimeStamp']);

                                $(document).resize();
                            }else if(rec['formType']==='I'){
                                mainContentFrame.remove();

                                if(body.hasClass('frame')) {
                                    body.removeClass('frame');
                                }

                                $(document).resize();
                            }
                        } else {

                        }
                    }
                }
            );
        },
        checkLogin: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;
            const modulePath = 'app=loginUser@';
            if(!o.checkingLogin) {
                o.checkingLogin = true;
                $.ajaxq ('widget',
                    {
                        url: proxyPath + modulePath + 'checkLogin',
                        contentType: 'application/x-www-form-urlencoded',
                        data: $(s.loginForm).serialize(),
                        type: 'POST',
                        dataType: 'json',
                        success: function (result) {
                            const session = result['s'];
                            if (result['resCode'] === 0) { //TODO NEW-CLASS
                                o.timeOutReDetect = session['sTimeoutReDetect'];
                                if (result['s']['sTimeout'] === true) {//TODO NEW-CLASS
                                    $this.stopLoginDetect();
                                    $this.showLoginScreen();
                                } else {
                                    $this.showWrapperScreen();
                                    $('body').removeClass('frame');
                                    $('#mainContentFrame').attr('src', null);
                                    $.DLN.clientManager.event.deSelectClient();
                                    o.loggedData.companies = result['records']['COMPANIES'];
                                    o.loggedData.companyDetail = result['records']['COMPANY_DETAIL'];
                                    //o.loggedData.companyId = session['companyId'];
                                    //o.loggedData.userId = session['userId'];
                                    $this.setCompanyId(session['companyId']);
                                    $this.setCompanyName(session['companyName']);
                                    $this.setIsOwner(session['isOwner']);
                                    $this.setLang(session['lang']);
                                    $this.setLangCultureInfo(session['langCultureInfo']);
                                    $this.setUserId(session['userId']);
                                    o.loggedData.personName = session['personName'];
                                    o.loggedData.personSurname = session['personSurname'];

                                    $(s.userInfo).html(session['personName'] + ' ' + session['personSurname']);
                                    $this.startLoginDetect();
                                }
                                if (o.loggedData.companyId != null && o.loggedData.userId != null) {
                                    if (session['companyId'] && session['userId']) {
                                        if (o.loggedData.companyId !== session['companyId'] ||
                                            o.loggedData.userId !== session['userId']) {//TODO NEW-CLASS
                                            $this.changeCompany();
                                            $this.setCompanyId(session['companyId']);
                                            $this.setCompanyName(session['companyName']);
                                            $this.setIsOwner(session['isOwner']);
                                            $this.setLang(session['lang']);
                                            $this.setLangCultureInfo(session['langCultureInfo']);
                                            $this.setUserId(session['userId']);
                                            o.loggedData.personName = session['personName'];
                                            o.loggedData.personSurname = session['personSurname'];
                                        }
                                    }
                                } else if (o.loggedData.companyId !== session['companyId'] ||
                                    o.loggedData.userId !== session['userId']) {//TODO NEW-CLASS
                                    $this.changeCompany();
                                    $this.setCompanyId(session['companyId']);
                                    $this.setCompanyName(session['companyName']);
                                    $this.setIsOwner(session['isOwner']);
                                    $this.setLang(session['lang']);
                                    $this.setLangCultureInfo(session['langCultureInfo']);
                                    $this.setUserId(session['userId']);
                                    o.loggedData.personName = session['personName'];
                                    o.loggedData.personSurname = session['personSurname'];
                                }
                                $this.setCompanyId(session['companyId']);
                                $this.setCompanyName(session['companyName']);
                                $this.setIsOwner(session['isOwner']);
                                $this.setLang(session['lang']);
                                $this.setLangCultureInfo(session['langCultureInfo']);
                                $this.setUserId(session['userId']);
                                o.loggedData.personName = session['personName'];
                                o.loggedData.personSurname = session['personSurname'];

                                $this.fillCompany();
                                /* if(!o.loggedData.isOwner) {

                                    $this.showMainSideBar();
                                }else{

                                    $this.hideMainSideBar();
                                } *///Here is closed for dont want to hide sidebar menu
                                $.DLN.clientManager.event.deSelectClient();
                                $.DLN.clientManager.search.init();
                                $.DLN.menuManager.init();
                            } else {
                                $this.showLoginScreen();
                                $this.changeCompany();
                                $this.setCompanyId(session['companyId']);
                                $this.setCompanyName(session['companyName']);
                                $this.setIsOwner(session['isOwner']);
                                $this.setLang(session['lang']);
                                $this.setLangCultureInfo(session['langCultureInfo']);
                                $this.setUserId(session['userId']);
                                o.loggedData.personName = session['personName'];
                                o.loggedData.personSurname = session['personSurname'];
                            }
                            o.checkedLoginFL = true;
                        },
                        error: function () {
                            o.checkingLogin = false;
                        },
                        complete: function () {
                            o.checkingLogin = false
                        }
                    }
                );
            }
        },
        startLoginDetect: function () {
            const $this = this;
            const o = $this.options;
            $this.stopLoginDetect();
            o.timeOutCont.push(setTimeout($this.checkLoginTimer, o.timeOutReDetect*1000*60, $this));
        },
        stopLoginDetect: function () {
            const $this = this;
            const o = $this.options;
            for(let i=0; i< o.timeOutCont.length; i++) {
                clearTimeout(o.timeOutCont[i]);
            }
            o.timeOutCont = [];
        },
        checkLoginTimer: function (obj, callback) {
            let $this;
            if (typeof (obj) === 'undefined' || obj === null) {
                $this = this;
            } else {
                $this = obj;
            }
            //console.log($this)
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
                            o.loggedData.lang = session['lang'];
                            o.loggedData.langCultureInfo = session['langCultureInfo'];
                            o.loggedData.userId = session['userId'];

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
        /*checkLoginTimerBack: function (obj) {
            const $this = obj;
            const o = $this.options;
            const modulePath = 'app=loginUser@';
            $.ajaxq ('widget',
                {
                    url:proxyPath + modulePath +'checkLoginTimer',
                    contentType:'application/x-www-form-urlencoded',
                    type:'POST',
                    dataType: 'json',
                    success : function( result) {
                        if(result['resCode'] === 0){//TODO NEW-CLASS
                            const session = result['s'];
                            if(session['sTimeout'] === true){//TODO NEW-CLASS
                                $this.showLoginScreen();
                            }else{
                                o.loginDetectError = 0;
                            }
                        }
                        $this.startLoginDetect();
                    },
                    error : function () {
                        o.loginDetectError++;
                        if(o.loginDetectError > 5){
                            $this.showLoginScreen();
                        }
                        $this.startLoginDetect();
                    }
                }
            );
        },*/
        changeCompany: function () {
            const $this = this;
            // const o = $this.options;
            // const s = o.selector;
            const modulePath = 'app=loginUser@';
            $.ajaxq ('widget',
                {
                    url:proxyPath + modulePath +'userLoginChangeCompany',
                    contentType:'application/x-www-form-urlencoded',
                    data: {
                        companyId: $('#companyId').val()
                    },
                    type:'POST',
                    dataType: 'json',
                    success : function( result) {
                        if(result['resCode'] !== 0){//TODO NEW-CLASS
                            $this.showLoginScreen();
                        }else{
                            //$this.checkLogin();//Closed on 2019.05.21

                        }
                        //$(window).resize();
                    }
                }
            );
        },
        setCompanyId: function (val) {
            const $this = this;
            const o = $this.options;
            const s = o.selector;
            if(o.loggedData.companyId !== val) {//TODO NEW-CLASS

                o.loggedData.companyId = val;
                $(s.companyId).val(val);
                const body = $('body');
                body.off('.client');
                body.off('.lang');
                body.trigger('companyChanged');
            }
        },
        setUserId: function (val) {
            const $this = this;
            const o = $this.options;
            if(o.loggedData.userId !== val) {//TODO NEW-CLASS
                o.loggedData.userId = val;
                $('body').trigger('userIdChanged');
            }
        },
        setCompanyName: function (val) {
            const $this = this;
            const o = $this.options;
            if(o.loggedData.companyName !== val) {//TODO NEW-CLASS
                o.loggedData.companyName = val;
                $('body').trigger('companyNameChanged');
            }
        },
        setIsOwner: function (val) {
            const $this = this;
            const o = $this.options;
            const body = $('body');
            if(!val){
                if(!body.hasClass('manage-db'))
                    body.addClass('manage-db');
            }else{
                body.removeClass('manage-db');
            }
            if(o.loggedData.isOwner !== val) {//TODO NEW-CLASS
                o.loggedData.isOwner = val;
                body.trigger('isOwnerChanged');
            }
        },
        setLang: function (val) {
            const $this = this;
            const o = $this.options;
            if(o.loggedData.lang !== val) {//TODO NEW-CLASS
                o.loggedData.lang = val;
                if(val != null) {
                    // if(o.checkedLoginFL) {
                        $.DLN.LangManager.loadGeneralResource();
                        $('body').trigger('langChanged');
                    // }
                }
            }
        },
        getLang: function () {
            const $this = this;
            const o = $this.options;
            return o.loggedData.lang;
        },
        setLangCultureInfo: function (val) {
            const $this = this;
            const o = $this.options;
            if(o.loggedData.langCultureInfo !== val) {//TODO NEW-CLASS
                moment.locale(val);
                o.loggedData.langCultureInfo = val;
                $('body').trigger('langCultureInfoChanged');
            }
        },
        getLangCultureInfo: function () {
            const $this = this;
            const o = $this.options;
            return o.loggedData.langCultureInfo;
        },
        /*hideMainSideBar: function () {
            const body = $('body');
            if(!body.hasClass('sidebar-collapse')){
                body.addClass('sidebar-collapse');
                $(window).trigger('resize');
            }
            $.DLN.clientManager.event.hideSelectedClient();
        },
        showMainSideBar: function () {
            const body = $('body');

            if(body.hasClass('sidebar-collapse')){
                body.removeClass('sidebar-collapse');
                $(window).trigger('resize');
            }
        }*/
    }
    // noinspection JSAnnotator
    $.DLN.menuManager = {
        options:{
            selector:{
                menuContainer:'.menu-content .menu',
                menuSearch:'#menuSearch',
                parentContiner: '.left-content',
                // template:'',
            },
            data: {
                created : false
            }
        },
        init: function () {
            const $this = this;
            // const o = $this.options;
            // const s = o.selector;
            /*if(!o.data.created) {
                $(s.parentContiner).append(s.template);
                o.data.created = true;
            }*/
            $this.loadMenuList();
            /*$('a.navbar-brand').click(function () {
                $('body').removeClass('frame');
            })*/
            $this.search();
        },
        search: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;
            $(s.menuSearch).keyup(
                function () {
                    $('a > span', s.menuContainer).each(function (i, e) {
                        let searchVal = $(s.menuSearch).val();
                        let menuTextVal = $(e).html();
                        if (searchVal.length === 0) {
                            $(e).parent().parent().show();
                            return;
                        }
                        $(e).parent().parent().hide();
                        searchVal = searchVal.replace('i', 'I').replace('ı', 'I').replace('İ', 'I').toUpperCase();
                        menuTextVal = menuTextVal.replace('i', 'I').replace('ı', 'I').replace('İ', 'I').toUpperCase();
                        if(menuTextVal.search(searchVal) >= 0){
                            $(e).parent().parent().show();
                        }

                    });

                    $('a+ul.nav-treeview', s.menuContainer).each(function (i, e) {
                        //Check this
                        if(!$(e).hasClass('menu-open') && !$(s.menuSearch).val().length === false) {
                            $(e).parent().addClass('menu-open');
                            $(e).show();
                            // $(e).slideDown();
                        }
                        $(e).parent().show();
                    });
                    if ($(s.menuSearch).val().length === 0) {
                        $('.has-treeview', s.menuContainer).removeClass('menu-open');
                    }else{
                        $('.has-treeview', s.menuContainer).addClass('menu-open');
                    }
                }
            );
        },
        loadMenuList: function () {
            const $this = this;
            const o = $this.options;
            const s = o.selector;

            const modulePath = 'app=loginUser@';
            $.ajaxq ('widget',
                {
                    url:proxyPath + modulePath +'menuList',
                    contentType:'application/x-www-form-urlencoded',
                    type:'POST',
                    dataType: 'json',
                    beforeSend : function( ) {
                        $(s.menuContainer).html('');
                    },
                    success : function( result) {
                        if(result['resCode'] === 0){

                            let items = [];


                            for(let i=0; i<result['records'].length; i++){
                                const rec = result['records'][i];
                                const parentMenuId = rec['parentMenu'] === null?rec['menuId']:rec['parentMenu']['menuId'];
                                const  index = items.findIndex(function(item) {
                                    return item['menuId'] === parentMenuId;
                                });
                                // console.log(item);
                                // console.log(parentMenuId);

                                if(index === -1){
                                    if(rec['parentMenu'] !== null){
                                        rec['parentMenu']['items'] = [];
                                        items.push(rec['parentMenu']);

                                        const firstIndex = items.findIndex(function(item) {
                                            return item['menuId'] === parentMenuId;
                                        });
                                        items[firstIndex]['items'].push(rec);

                                    }else{
                                        rec['items'] = [];
                                        items.push(rec);
                                    }

                                }else{

                                    items[index]['items'].push(rec);
                                }

                            }
                            const mainMenuList = Handlebars.compile($('#mainMenuList').html());
                            Handlebars.registerPartial( 'menuList', $( '#menuList' ).html() );
                            $(s.menuContainer).html( mainMenuList( { items: items } ) );
                            $(s.menuContainer).find('a[data-url]').click($this.clickMenu)

                            $('.has-treeview > a').click(function () {
                                if($(this).parent().hasClass('menu-open')){
                                    $(this).parent().find('ul').slideUp();
                                }else{
                                    $(this).parent().find('ul').slideDown();
                                }
                                $(this).parent().toggleClass('menu-open');
                            });
                        }
                    },
                    error : function (xhr, textStatus, errorThrown) {
                    },
                    complete : function ( xhr, textStatus ) {
                    }
                }
            );
        },
        clickMenu: function () {
            // $('#mainContentDiv').html('');
            const type = $(this).attr('data-type');
            const url = $(this).attr('data-url');
            // const relativePath = $(this).attr('data-relativePath');
            //N = Normal
            //I = Inner Frame
            //O = Popup
            //P = Print Direct
            // FORM_TYPE
            // F tr="FL_INNER_FRAME
            // B tr="FL_ABOUT_BLANK
            // N tr="FL_NEW_PAGE
            // I tr="FL_INNER_HTML
            $('.sidebar-menu a.clicked').removeClass('clicked');
            $(this).addClass('clicked');
            const mainContentDiv = $('#mainContentDiv');
            const mainContentFrame = $('#mainContentFrame');
            mainContentDiv.html('');
            if(mainContentFrame.length > 0) {
                const frame = document.getElementById("mainContentFrame");
                const frameDoc = frame.contentDocument || frame.contentWindow.document;
                frameDoc.documentElement.innerHTML = "";
            }
            const body = $('body');

            if(type === 'F'){//TODO NEW-CLASS

                if(mainContentFrame.length === 0) {
                    const f = $('<iframe/>', {
                        id: 'mainContentFrame',
                        name: 'mainContentFrame',
                        allowtransparency: 'true',
                        class: 'main-content-frame'
                    });
                    $('.content-frame').append(f);
                }
                if(!body.hasClass('frame')) {
                    body.addClass('frame');
                }
                mainContentFrame.attr('src', url);

                $(document).resize();
            }else if(type==='I'){
                mainContentFrame.remove();

                if(body.hasClass('frame')) {
                    body.removeClass('frame');
                }
                // loadURL(url);//TODO NEW-CLASS
                /*$.ajax(
                    {
                        url: url,
                        type: "GET",
                        beforeSend: function (xhr, settings) {
                            console.log('beforeSend');

                            //$('body').blk();
                        },
                        success: function (result, textStatus, xhr) {
                            workInFrame = true;
                            var result = result.replace('src="js/','src="'+relativePath+'js/')

                            var scriptArray = [];
                            var rp = /<script\b[^>]([\s\S]*?)>*<\/script>/gm;

                            var matchScript;
                            while (matchScript = rp.exec(result)) {
                                var src = matchScript[1].replace('src="','').replace('"','');
                                scriptArray.push(src);
                            }
                            for(var si=0; si< scriptArray.length; si++){


                                if(scriptArray[si].indexOf('../../lib/') !== -1 /!*&& scriptArray[si].indexOf('lib.js') !== -1*!/) {
                                    result = result.replace('<script src="' + scriptArray[si] + '"></script>', '');
                                }else{
                                    //$.getScript(scriptArray[si]);
                                }


                            }
                            var cssArray = [];
                            rp = /<link\b[^>]([\s\S]*?)>/gm;
                            var matchCss;
                            while (matchCss = rp.exec(result)) {
                                var src = matchCss[1].replace('rel="stylesheet" href="','').replace('"','');
                                console.log(src);

                                cssArray.push(src);
                            }
                            for(var ci=0; ci< cssArray.length; ci++){


                                if(cssArray[ci].indexOf('../../lib/') !== -1 /!*&& scriptArray[si].indexOf('lib.js') !== -1*!/) {
                                    result = result.replace('<link rel="stylesheet" href="'+cssArray[ci]+'">', '');
                                }else{
                                }


                            }
                            // $('#mainContentDiv').html(result);
                            $('.middle-content').html(result);
                            console.log(result);

                        },
                        complete: function (xhr, textStatus) {
                            //$('body').ublk();
                        }
                    }
                );*/

                $(document).resize();
            }
        }

    }
});
