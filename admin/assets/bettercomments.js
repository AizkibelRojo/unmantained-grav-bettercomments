$(document).on('click tap', '.individual_comment .approve-comment, .individual_comment .decline-comment, .individual_comment .delete-comment', function (event) {
    const action = $(this).attr('class'),
        file = $(this).parent('td').data('file'),
        commenttId = $(this).parent('td').data('id'),
        url = window.location.pathname,
        posting = $.post(url, { action: action, yaml: file, id: commenttId }, null, 'json')

    let blockChange = '',
        actionClass = '',
        extraButton = ''

    posting.done(function (response) {
        if (response.status) {
            switch (action) {
                case 'approve-comment': case 'decline-comment':
                    if (action == 'approve-comment') {
                        actionClass = 'decline-comment'
                        if (response.texts[4]) {
                            extraButton = '<span class="answer-comment">' + response.texts[4] + '</span> | '
                        }
                    }
                    else {
                        actionClass = 'approve-comment'
                    }
                    blockChange = response.texts[0] + ' - ' + response.texts[3] +
                        '<br />' +
                        extraButton +
                        '<span class="' + actionClass + '">' + response.texts[1] + '</span> | <span class="delete-comment">' + response.texts[2] + '</span>'
                    break
            }
            $('#comment-' + response.data).html(blockChange).ready(function () {
                $('#comment-' + response.data).append('<span id="comment-' + response.data + '-succes" class="comment-succes">' + response.message + '</span>').ready(function () {
                    setTimeout(function () {
                        $('#comment-' + response.data + '-succes').fadeOut(function () {
                            $('#comment-' + response.data + '-succes').remove()

                            if (action === 'delete-comment') {
                                $('#comment-' + response.data + '-line').fadeOut()
                            }
                        })
                    }, 2500)
                })
            })
        }
        else {
            $('#comment-' + response.data).append('<span id="comment-' + response.data + '-error" class="comment-error">' + response.message + '</span>').ready(function () {
                setTimeout(function () {
                    $('#comment-' + response.data + '-error').fadeOut(function () {
                        $('#comment-' + response.data + '-error').remove()
                    })
                }, 2500)
            })
        }
    })
})

$(document).on('click tap', '.individual_comment .answer-comment', function (event) {
    const commentId = $(this).parent('td').data('id'),
        commentText = $('tr#comment-' + commentId + '-line > td.comment').text(),
        commentTitle = $('td#comment-' + commentId).data('title'),
        file = '/blog/' + $('td#comment-' + commentId).data('file-name').replace('.yaml', '')
    $('#answer-comment-input').val(commentId)
    $('#title-comment-input').val(commentTitle)
    $('#file-comment-input').val(file)
    $('#comment-to-answer').html(commentText)
    $('#admin-comment').fadeIn()
})

$(document).on('click tap', '.comment-response-close', function (event) {
    event.preventDefault()
    $('#admin-comment').fadeOut()
})

$(document).on('submit', '#comments-form', function (event) {
    event.preventDefault()
    const data = $(this).serialize(),
        url = window.location.pathname,
        posting = $.post(url, data, null, 'json')

    posting.done(function (response) {
        const commentBlock = '<tr id="comment-' + response.data[4] + '-line" class="individual_comment">' +
            '<td class="status">' +
            response.texts[0] + ' - ' + response.texts[1] +
            '<br />' +
            '<span class="decline-comment">' + response.texts[2] + '</span> | <span class="delete-comment">' + response.texts[3] + '</span>' +
            '</td><td class="author">' +
            response.data[0] +
            '</td><td class="email">' +
            response.data[1] +
            '</td><td class="comment">' +
            response.data[2] +
            '</td><td class="details">' +
            '<strong>' + response.texts[4] + '</strong>: ' + response.data[3] +
            '<br>' +
            '<strong>' + response.texts[5] + '</strong>: ' + response.data[4] +
            '</td></tr>'

        $('.js__comments-container tr:first').after(commentBlock)
        $('#comments-form')[0].reset()
        $('#admin-comment').fadeOut()
    })
})

$(function () {
    let currentPage = 0

    $(document).on('click tap', '.js__load-more', function (event) {
        currentPage = currentPage + 1
        $.ajax({
            url: window.location + '/page:' + currentPage,
            dataType: 'json',
            success: function (response) {
                currentPage = parseInt(response.page)
                let comentBlock = '',
                    statusBlock = ''
                response.comments.forEach(function (comment) {
                    if (comment.approved != "2") {
                        const commentDate = new Date(parseInt(comment.date * 1000)),
                            commentDay = commentDate.getDate(),
                            commentMonth = commentDate.getMonth() + 1,
                            commentYear = commentDate.getFullYear(),
                            commentHour = commentDate.getFullYear(),
                            commentMinutes = commentDate.getFullYear()

                        if (comment.approved == "1") {
                            //approved comment controls
                            statusBlock = response.textsForComposition[0] +
                                '<br/>' +
                                '<span class="delete-comment">' + response.textsForComposition[5] + '</span>'
                            ' | ' +
                                '<span class="decline-comment">' + response.textsForComposition[4] + '</span>' +
                                ' | ' +
                                '<span class="delete-comment">' + response.textsForComposition[3] + '</span>'
                        }
                        else {
                            //moderation commet controls
                            statusBlock = response.textsForComposition[1] +
                                '<br/>' +
                                '<span class="approve-comment">' + response.textsForComposition[2] + '</span>' +
                                ' | ' +
                                '<span class="delete-comment">' + response.textsForComposition[3] + '</span>'
                        }
                        //create commet block to apppend
                        comentBlock = '<tr class="individual_comment" id="comment-' + comment.date + '-line">' +
                            '<td class="status" data-file="' + comment.filePath + '" data-id="' + comment.timestamp + '" id="comment-' + comment.date + '">' +
                            statusBlock +
                            '</td>' +
                            '<td class="author">' + comment.author + '</td>' +
                            '<td class="email">' + comment.email + '</td>' +
                            '<td class="comment">' + comment.text + '</td>' +
                            '<td class="details"><strong>' + response.textsForComposition[6] + '</strong>: ' + comment.pageTitle + '<br>' + '<strong>' + response.textsForComposition[7] + '</strong>: ' + commentDay + '/' + commentMonth + '/' + commentYear + ' ' + commentHour + ':' + commentMinutes + '</td>' +
                            '</tr>'

                        $('.js__comments-container').append(comentBlock)

                    }
                })

                let totalRetrieved = response.comments.length + (currentPage * 30)

                $('.totalRetrieved').html(totalRetrieved)
                $('.totalAvailable').html(response.totalAvailable)

                if (totalRetrieved == response.totalAvailable) {
                    $('.js__load-more').hide()
                }
            }
        })
    })
})