/**
 * Created by ferhat on 16.10.2020
 */
/** DLNApp Plugins & Function
 *
 **/
$(document).ready(function () {

    const searchResult = $('.search-result-container');

    // setTimeout($.DLN.LangManager.setLanguageTemplate(),5000);
    if($(window).width() < 575.98){
        $('.mf-container .header .left-header').removeClass("show");
        $('.mf-container .content-wrapper .left-content').removeClass("show");
        $('.mf-container .content-wrapper .middle-content').removeClass("show");
        // $('.search-result-container').css({left:"0"});
        searchResult.css({left:"0"});

    }

    $('.left-header-close').click(function () {
        $(this).closest('.left-header').toggleClass("show");
        const leftContent = $('.left-content');
        const middleContent = $('.middle-content');
        leftContent.toggleClass("show");
        middleContent.toggleClass("show");
        /*
        if(leftContent.width()>100) {
            leftContent.css({width: '0px'});
        }else{
            leftContent.css({width: '250px'});
        }
         */
    });

    $(document).mouseup(function (e) {
        if($(e.target).closest('.patient-search-container').length === 0)
            if ($(e.target).closest('.search-result-container').length === 0) {
                $('.search-result-container').hide();
            }

        if($(e.target).closest('.patient-search-container').length !== 0){
            const patientSearchContainer = $('.patient-search-container');
            const offsetSearchContainer = patientSearchContainer.offset();
            const searchResult = $('.search-result-container');

            //searchResult.width(patientSearchContainer.width()-3);
            searchResult.css({left:offsetSearchContainer.left});
            // searchResult.toggle();
            searchResult.show();
            // showPrintPage('./module/pcr_patient/patient-1.0.0.html');
        }
    });



    const patientSearchContainer = $('.patient-search-container');
    const offsetSearchContainer = patientSearchContainer.offset();

    //searchResult.width(patientSearchContainer.width()-3);
    searchResult.css({left:offsetSearchContainer.left});
    $.DLN.loginManager.init();
/*
    let rnd = Math.floor(Math.random() * 15)
    if(rnd === 0){
        rnd = 1;
    }
    if(rnd.toString().length === 1){
        rnd = "0"+rnd.toString();
    }*/
    //console.log('background-image: url("./lib/app/images/backgrounds/login-big-bg-' + rnd + '.jpg")');

    //$('body').css('background-image','url("./lib/app/images/backgrounds/login-big-bg-'+rnd+'.jpg")')

});

//wawes function
;(function (window) {
    'use strict';

    let Waves = /*Waves ||*/ {};
    const $$ = document.querySelectorAll.bind(document);

    // Find exact position of element
    function isWindow(obj) {
        return obj !== null && obj === obj.window;
    }

    function getWindow(elem) {
        return isWindow(elem) ? elem : elem.nodeType === 9 && elem.defaultView;
    }

    function offset(elem) {
        let docElem, win,
            box = {top: 0, left: 0},
            doc = elem && elem.ownerDocument;

        docElem = doc.documentElement;

        if (typeof elem.getBoundingClientRect !== typeof undefined) {
            box = elem.getBoundingClientRect();
        }
        win = getWindow(doc);
        return {
            top: box.top + win.pageYOffset - docElem.clientTop,
            left: box.left + win.pageXOffset - docElem.clientLeft
        };
    }

    function convertStyle(obj) {
        let style = '';

        for (const a in obj) {
            if (obj.hasOwnProperty(a)) {
                style += (a + ':' + obj[a] + ';');
            }
        }

        return style;
    }

    const Effect = {

        // Effect delay
        duration: 750,

        show: function (e, element) {

            // Disable right click
            if (e.button === 2) {
                return false;
            }

            const el = element || this;

            // Create ripple
            const ripple = document.createElement('div');
            ripple.className = 'waves-ripple';
            el.appendChild(ripple);

            // Get click coordinate and element witdh
            const pos = offset(el);
            let relativeY = (e.pageY - pos.top);
            let relativeX = (e.pageX - pos.left);
            const scale = 'scale(' + ((el.clientWidth / 100) * 10) + ')';

            // Support for touch devices
            if ('touches' in e) {
                relativeY = (e.touches[0].pageY - pos.top);
                relativeX = (e.touches[0].pageX - pos.left);
            }

            // Attach data to element
            ripple.setAttribute('data-hold', Date.now().toString());
            ripple.setAttribute('data-scale', scale);
            ripple.setAttribute('data-x', relativeX.toString());
            ripple.setAttribute('data-y', relativeY.toString());

            // Set ripple position
            const rippleStyle = {
                'top': relativeY + 'px',
                'left': relativeX + 'px'
            };

            ripple.className = ripple.className + ' waves-notransition';
            ripple.setAttribute('style', convertStyle(rippleStyle));
            ripple.className = ripple.className.replace('waves-notransition', '');

            // Scale the ripple
            rippleStyle['-webkit-transform'] = scale;
            rippleStyle['-moz-transform'] = scale;
            rippleStyle['-ms-transform'] = scale;
            rippleStyle['-o-transform'] = scale;
            rippleStyle.transform = scale;
            rippleStyle.opacity = '1';

            rippleStyle['-webkit-transition-duration'] = Effect.duration + 'ms';
            rippleStyle['-moz-transition-duration'] = Effect.duration + 'ms';
            rippleStyle['-o-transition-duration'] = Effect.duration + 'ms';
            rippleStyle['transition-duration'] = Effect.duration + 'ms';

            rippleStyle['-webkit-transition-timing-function'] = 'cubic-bezier(0.250, 0.460, 0.450, 0.940)';
            rippleStyle['-moz-transition-timing-function'] = 'cubic-bezier(0.250, 0.460, 0.450, 0.940)';
            rippleStyle['-o-transition-timing-function'] = 'cubic-bezier(0.250, 0.460, 0.450, 0.940)';
            rippleStyle['transition-timing-function'] = 'cubic-bezier(0.250, 0.460, 0.450, 0.940)';

            ripple.setAttribute('style', convertStyle(rippleStyle));
        },

        hide: function (e) {
            TouchHandler.touchup(e);

            const el = this;
            // const width = el.clientWidth * 1.4;

            // Get first ripple
            let ripple = null;
            const ripples = el.getElementsByClassName('waves-ripple');
            if (ripples.length > 0) {
                ripple = ripples[ripples.length - 1];
            } else {
                return false;
            }

            const relativeX = ripple.getAttribute('data-x');
            const relativeY = ripple.getAttribute('data-y');
            const scale = ripple.getAttribute('data-scale');

            // Get delay beetween mousedown and mouse leave
            const diff = Date.now() - Number(ripple.getAttribute('data-hold'));
            let delay = 350 - diff;

            if (delay < 0) {
                delay = 0;
            }

            // Fade out ripple after delay
            setTimeout(function () {
                const style = {
                    'top': relativeY + 'px',
                    'left': relativeX + 'px',
                    'opacity': '0',

                    // Duration
                    '-webkit-transition-duration': Effect.duration + 'ms',
                    '-moz-transition-duration': Effect.duration + 'ms',
                    '-o-transition-duration': Effect.duration + 'ms',
                    'transition-duration': Effect.duration + 'ms',
                    '-webkit-transform': scale,
                    '-moz-transform': scale,
                    '-ms-transform': scale,
                    '-o-transform': scale,
                    'transform': scale,
                };

                // noinspection JSUnresolvedFunction
                ripple.setAttribute('style', convertStyle(style));

                setTimeout(function () {
                    try {
                        el.removeChild(ripple);
                    } catch (e) {
                        return false;
                    }
                }, Effect.duration);
            }, delay);
        },

        // Little hack to make <input> can perform waves effect
        wrapInput: function (elements) {
            for (let a = 0; a < elements.length; a++) {
                const el = elements[a];

                if (el.tagName.toLowerCase() === 'input') {
                    const parent = el.parentNode;

                    // If input already have parent just pass through
                    if (parent.tagName.toLowerCase() === 'i' && parent.className.indexOf('waves-effect') !== -1) {
                        continue;
                    }

                    // Put element class and style to the specified parent
                    const wrapper = document.createElement('i');
                    wrapper.className = el.className + ' waves-input-wrapper';

                    let elementStyle = el.getAttribute('style');

                    if (!elementStyle) {
                        elementStyle = '';
                    }

                    wrapper.setAttribute('style', elementStyle);

                    el.className = 'waves-button-input';
                    el.removeAttribute('style');

                    // Put element as child
                    parent.replaceChild(wrapper, el);
                    wrapper.appendChild(el);
                }
            }
        }
    };


    /**
     * Disable mousedown event for 500ms during and after touch
     */
    const TouchHandler = {
        /* uses an integer rather than bool so there's no issues with
         * needing to clear timeouts if another touch event occurred
         * within the 500ms. Cannot mouseup between touchstart and
         * touchend, nor in the 500ms after touchend. */
        touches: 0,
        allowEvent: function (e) {
            let allow = true;

            if (e.type === 'touchstart') {
                TouchHandler.touches += 1; //push
            } else if (e.type === 'touchend' || e.type === 'touchcancel') {
                setTimeout(function () {
                    if (TouchHandler.touches > 0) {
                        TouchHandler.touches -= 1; //pop after 500ms
                    }
                }, 500);
            } else if (e.type === 'mousedown' && TouchHandler.touches > 0) {
                allow = false;
            }

            return allow;
        },
        touchup: function (e) {
            TouchHandler.allowEvent(e);
        }
    };


    /**
     * Delegated click handler for .waves-effect element.
     * returns null when .waves-effect element not in "click tree"
     */
    function getWavesEffectElement(e) {
        if (TouchHandler.allowEvent(e) === false) {
            return null;
        }

        let element = null;
        let target = e.target || e.srcElement;

        while (target.parentElement !== null) {
            if (!(target instanceof SVGElement) && target.className.indexOf('waves-effect') !== -1) {
                element = target;
                break;
            } else if (target.classList.contains('waves-effect')) {
                element = target;
                break;
            }
            target = target.parentElement;
        }

        return element;
    }

    /**
     * Bubble the click and show effect if .waves-effect elem was found
     */
    function showEffect(e) {
        const element = getWavesEffectElement(e);

        if (element !== null) {
            Effect.show(e, element);

            if ('ontouchstart' in window) {
                element.addEventListener('touchend', Effect.hide, false);
                element.addEventListener('touchcancel', Effect.hide, false);
            }

            element.addEventListener('mouseup', Effect.hide, false);
            element.addEventListener('mouseleave', Effect.hide, false);
        }
    }

    Waves.displayEffect = function (options) {
        options = options || {};

        if ('duration' in options) {
            Effect.duration = options.duration;
        }

        //Wrap input inside <i> tag
        Effect.wrapInput($$('.waves-effect'));

        if ('ontouchstart' in window) {
            document.body.addEventListener('touchstart', showEffect, false);
        }

        document.body.addEventListener('mousedown', showEffect, false);
    };

    /**
     * Attach Waves to an input element (or any element which doesn't
     * bubble mouseup/mousedown events).
     *   Intended to be used with dynamically loaded forms/inputs, or
     * where the user doesn't want a delegated click handler.
     */
    Waves.attach = function (element) {
        //FUTURE: automatically add waves classes and allow users
        // to specify them with an options param? Eg. light/classic/button
        if (element.tagName.toLowerCase() === 'input') {
            Effect.wrapInput([element]);
            element = element.parentElement;
        }

        if ('ontouchstart' in window) {
            element.addEventListener('touchstart', showEffect, false);
        }

        element.addEventListener('mousedown', showEffect, false);
    };

    window.Waves = Waves;

    document.addEventListener('DOMContentLoaded', function () {
        Waves.displayEffect();
    }, false);

})(window);