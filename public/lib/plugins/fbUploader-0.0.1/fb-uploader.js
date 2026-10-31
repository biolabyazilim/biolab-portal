(function( $ ) {
    $.fn.fileUploader = function (options) {
        var Upload = function (file, el) {
            this.file = file;
            this.container = el;
        };

        Upload.prototype.getType = function() {
            return this.file.type;
        };
        Upload.prototype.getSize = function() {
            return this.file.size;
        };
        Upload.prototype.getName = function() {
            return this.file.name;
        };
        Upload.prototype.getExtension = function() {
            var fileNameSplitted = this.file.name.split('.');

            var extension = null;
            if(fileNameSplitted.length > 1) {
                extension = fileNameSplitted[fileNameSplitted.length -1];
            }
            return extension
        };
        Upload.prototype.doUpload = function () {
            var that = this;
            var formData = new FormData();

            // add assoc key values, this will be posts values
            formData.append("file", this.file, this.getName());
            formData.append("upload_file", true);
            formData.append("file_size", this.getSize());
            formData.append("file_type", this.getType());
            formData.append("file_name", this.getName());
            formData.append("file_ext", this.getExtension());


            $.ajax({
                type: "POST",
                url: settings.ajax.url,
                xhr: function () {
                    var myXhr = $.ajaxSettings.xhr();
                    if (myXhr.upload) {
                        myXhr.upload.addEventListener('progress', that.progressHandling, false);
                    }
                    return myXhr;
                },
                success: function (result, textStatus, xhr) {
                    if(settings.returnStatus) {
                        if (settings.returnStatus(result)) {
                            if(settings.dataField) {
                                output.addRow(that.container, result[settings.dataField]);
                            }else{
                                output.addRow(that.container, result);
                            }
                            if (settings.ajax.success) {
                                settings.ajax.success(that.container, result);

                            }
                        }
                    }
                },
                complete: function (xhr, textStatus) {
                    var input = $(this).closest('.fb-input').find('.fb-input-caption').find('span');
                    input.html('');
                    $(that.container).find(':file').val('').change();
                },
                error: function (error) {
                    // handle error
                },
                async: true,
                data: formData,
                cache: false,
                contentType: false,
                processData: false,
                timeout: 60000,
                dataType: "json"
            });
        };

        Upload.prototype.progressHandling = function (event) {
            var percent = 0;
            var position = event.loaded || event.position;
            var total = event.total;
            var $progressBar = $(settings.progressBarSelector);
            if (event.lengthComputable) {
                percent = Math.ceil(position / total * 100);
            }
            // update progressbars classes so it fits your code
            $progressBar.find(".progress-bar").css("width", +percent + "%").prop('valuenow',percent);
            $progressBar.find("span").html(percent + "%");
        };

        var defaults = {
            ajax:{
                url:null,

            },
            progressBarSelector:'.progress',
            secondRowTmpl: '',
            dataField:null,
            rowTmpl:'' +
            '<li class="fb-item">\n' +
            '           <input type="hidden" id="DELETED_FL" name="DELETED_FL[]" value="0" class="DELETED_FL"/>' +
            '   <div class="fb-item-row">\n' +
            '       <div class="fb-item-row-first">\n' +
            '           <div class="item-thumbnail">\n' +
            '               <div class="item-image">\n' +
            '                   <div class="icon">\n' +
            '                       <i>{{ext}}</i>\n' +
            '                   </div>\n' +
            '               </div>\n' +
            '           </div>\n' +
            '           <div class="item-title">\n' +
            '               <span class="file-name">{{name}}</span>\n' +
            '               <span class="file-size">{{size}} KB</span>\n' +
            '           </div>\n' +
            '            <div class="item-actions">\n' +
            '               <a class="action-button act-del">\n' +
            '                   <i class="fa fa-trash-alt"></i>\n' +
            '               </a>\n' +
            '           </div>\n' +
            '       </div>\n' +
            '       <div class="fb-item-row-second">\n' +
            '       </div>\n' +
            '   </div>\n' +
            '</li>'
        };
        var settings = $.extend(true, defaults, options);

        var that = this;
        var output = {
            init: function () {
                that.each(function (i, el) {
                    var postData = null;
                    init(el);
                });
            },
            addRow: function (el, data) {
                // function addRow(el, data) {
                    var tmplData = settings.rowTmpl;

                    var tmplDataHB = Handlebars.compile(tmplData);
                    var tmplDataHTML = tmplDataHB(data);
                    $(el).find('ul').append(tmplDataHTML);

                    if(settings.removeRow){
                        $(el).find('ul li:last-child').find('.act-del').click(settings.removeRow);
                    }else {
                        $(el).find('ul li:last-child').find('.act-del').click(output.removeRow);
                    }
                    var secondRowTmplData = settings.secondRowTmpl;
                    tmplDataHB = Handlebars.compile(secondRowTmplData);
                    tmplDataHTML = tmplDataHB(data);
                    $(el).find('ul li:last-child').find('.fb-item-row-second').append(tmplDataHTML);
                    $(el).find('ul li:last-child').attr('data-id', data[settings.pkFieldName]);
                // }
            },
            removeRow: function () {
                // function removeRow(){
                if($(this).closest('li').data('id')> 0){
                    $(this).closest('li').hide();
                    $(this).closest('li').find('.DELETED_FL').val(1);
                }else {
                    $(this).closest('li').remove();
                }
                // }
            }
        };
        output.init();
        return output;


        function init(el){
            $(el).find(':file').on('change', function () {
                var input = $(this);
                var numFiles = input.get(0).files ? input.get(0).files.length : 1;
                var label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
                input.trigger('fileselect', [numFiles, label]);
            });

            $(el).find(':file').on('fileselect', function (event, numFiles, label) {

                var input = $(this).closest('.fb-input').find('.fb-input-caption').find('span')
                var log = numFiles > 1 ? numFiles + ' files selected' : label;

                if(log != ''){
                    $(this).closest('.fb-input').find('.btn-upload').removeAttr('disabled');
                }else{
                    $(this).closest('.fb-input').find('.btn-upload').attr('disabled','disabled');
                    log = '...';
                    setTimeout(function () {
                        var percent = 0;
                        $(settings.progressBarSelector).find(".progress-bar").css("width", +percent + "%").prop('valuenow',percent);
                        $(settings.progressBarSelector).find("span").html(percent + "%");
                    },2000);

                }
                if (input.length) {
                    input.html(log);
                } else {
                    //if( log ) alert(log);
                }

            });
            $(el).find('.btn-upload').click(function () {
                var upload = new Upload($(el).find(':file')[0].files[0],el);
                upload.doUpload();
            });
        }


    };

})( jQuery );
