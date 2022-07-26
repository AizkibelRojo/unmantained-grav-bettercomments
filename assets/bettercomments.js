$(document).on('submit', '#comments-form', function (event) {
    event.preventDefault()

    let data = $(this).serialize()

    if (grecaptcha.getResponse() == "") {
        data = { action: "recaptcha-error" }
    }
    const url = $(this).attr("action"),
        posting = $.post(url, data, null, 'json')

    posting.done(function (response) {
        if (response.status) {
            //append new comment
            $('#comment-errors').hide()
            $('#comments-form')[0].reset()
            $('#comment-success').fadeIn().promise().done(function () {
                setTimeout(function () {
                    $("#comment-success").fadeOut()
                }, 2500)
            })
            grecaptcha.reset()
        }
        else {
            //show errors
            $('#comment-errors').html('').promise().done(function () {
                $('#comment-errors').fadeIn()
                response.errors.forEach(errorElement => {
                    $('#comment-errors').append(errorElement + '<br/>')
                });
            });
        }
    })
})