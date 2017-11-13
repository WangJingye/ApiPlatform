$(function(){
    $('.change-password-btn').click(function () {
        var checked=$('.user-list').find('input[type=checkbox][name=user_id]:checked');
        if(checked.length!=1){
            alert('请选择一个用户');
            return false;
        }
        var model = $('#change-password-modal');
        model.find('[name=user_id]').val(checked.val());
        model.modal('toggle');
    });
    $('.datetime-picker').datetimepicker({
        format: 'yyyy-mm-dd',
        language:  'zh-CN',
        weekStart: 1,
        todayBtn:  1,
        autoclose: 1,
        todayHighlight: 1,
        startView: 2,
        minView: 2,
        forceParse: 0
    });
});