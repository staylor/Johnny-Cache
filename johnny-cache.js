"use strict";

/*globals window, document, $, jQuery */

(function ($) {
    var instance_store, refr, current, slctr;

    function remove_group(el) {
        return function () {
            el.closest('tr').fadeOut().remove();
        };
    }
    
    function remove_item(el) {
        return function () {
            el.closest('p').fadeOut().remove();
        };
    }

    function add_instance(data) {
        instance_store.html(data);
        refr.show();
    }

    $(document).ready(function () {
        instance_store = $('#instance-store');
        refr = $('#refresh-instance');
        slctr = $('#instance-selector');
        
        refr.click(function () {
            slctr.trigger('change');
            return false;
        });
        
        slctr.bind('change', function () {
            var val = $(this).val();
            if ('' !== jQuery.trim(val)) {
                var elem = $(this);
                $.ajax({
                    url     : '/wp-admin/admin-ajax.php',
                    data    : {
                        action : 'jc-get-instance',
                        nonce  : elem.attr('data-nonce'),
                        name   : val
                    },
                    cache   : false,
                    success : function (data) {
                        if (data) {
                            add_instance(data);
                            current = val;
                        }
                    }
                });               
            }
        });
        
        $('.jc-flush-group').live('click', function () { 
            var elem = $(this), keys = [];
            
            elem.parents('td').next().find('p').each(function () {
                keys.push($(this).attr('data-key'));
            });
            
            $.ajax({ 
                url     : elem.attr('href'),
                type    : 'post',
                data    : {
                    keys: keys
                },
                success : remove_group(elem)
            });
            return false; 
        });
        $('.jc-remove-item').live('click', function () { 
            var elem = $(this);
            $.ajax({
                url     : elem.attr('href'),
                success : remove_item(elem)
            });
            return false; 
        });
        
        $('.jc-view-item').live('click', function () {
            var elem = $(this);
            $.ajax({ 
                url     : elem.attr('href'),
                type    : 'post',
                success : function (data) {
                    $('#debug').html(data);
                    window.location.hash = 'jc-wrapper';
                }
            });
            
            return false;
        });
    });

}(jQuery));