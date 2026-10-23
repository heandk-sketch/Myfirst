/* ---- Pure helper functions (also exported for unit tests) ---- */

function _golfscoreDetectManualHole() {
    return /[?&]hole=/.test(window.location.search);
}

function _golfscoreUpdateLiveScorecardTotal($, playerId, selectedScore) {
    var hole = parseInt($('#golfscore-hole-form input[name="hole"]').val(), 10);
    if (!playerId || !hole || isNaN(selectedScore)) {
        return;
    }

    var $row = $('.golfscore-scorecard-table tr[data-player-id="' + playerId + '"]');
    if (!$row.length) {
        return;
    }

    var $currentHoleCell = $row.find('td[data-hole="' + hole + '"]');
    if ($currentHoleCell.length) {
        $currentHoleCell.text(selectedScore);
    }

    var total = 0;
    $row.find('td[data-hole]').each(function() {
        var val = parseInt($(this).text(), 10);
        if (!isNaN(val)) {
            total += val;
        }
    });

    var $totalCell = $row.find('td.total');
    if ($totalCell.length) {
        $totalCell.text(total > 0 ? total : '-');
    }
}

function _golfscoreApplyRemoteHoleScores($, holeScores) {
    if (!holeScores) {
        return;
    }

    $('.golfscore-player-input').each(function() {
        var $row = $(this);
        var $buttons = $row.find('.golfscore-score-btn');
        if (!$buttons.length) {
            return;
        }

        var playerId = parseInt($buttons.first().data('player-id'), 10);
        if (!playerId) {
            return;
        }

        var remoteScore = holeScores[playerId];
        var normalized = parseInt(remoteScore, 10);
        var $hiddenInput = $row.find('.golfscore-player-score');

        if (isNaN(normalized) || normalized <= 0) {
            $buttons.removeClass('active');
            $hiddenInput.val('');
            return;
        }

        $buttons.removeClass('active');
        $buttons.filter('[data-score="' + normalized + '"]').addClass('active');
        $hiddenInput.val(normalized);
    });
}

/* ---- jQuery ready ---- */

jQuery(document).ready(function($) {
    console.log('GolfScore JavaScript loaded and ready');
    var pollSessionNow = null;
    
    // Create new session
    $('#golfscore-new-session-form').on('submit', function(e) {
        e.preventDefault();
        
        var sessionName = $('input[name="session_name"]').val();
        var nonce = $('input[name="golfscore_nonce"]').val();
        
        console.log('Creating session with nonce:', nonce, 'and name:', sessionName);
        
        $.ajax({
            type: 'POST',
            url: golfscoreData.ajaxurl,
            data: {
                action: 'golfscore_create_session',
                session_name: sessionName,
                nonce: nonce
            },
            success: function(response) {
                console.log('Response:', response);
                if (response.success) {
                    $('#session-message').html(response.data.message).show();
                    $('input[name="session_name"]').val('');
                    
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('#session-message').html('Error: ' + (response.data.message || 'Unknown error')).show();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr, status, error);
                $('#session-message').html('Error creating session: ' + error).show();
            }
        });
    });

    // End session
    $('.golfscore-end-session').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to end this session?')) {
            return;
        }
        
        var sessionId = $(this).data('session-id');
        var nonce = golfscoreData.nonce;
        
        $.ajax({
            type: 'POST',
            url: golfscoreData.ajaxurl,
            data: {
                action: 'golfscore_end_session',
                session_id: sessionId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                }
            }
        });
    });

    // Add players (supports comma-separated names)
    console.log('Setting up add player form handler');
    
    function handleAddPlayer(e) {
        console.log('Add Player button clicked!');
        e.preventDefault();
        e.stopPropagation();

        var formContainer = $('#golfscore-add-player-form');
        var sessionId = formContainer.find('input[name="session_id"]').val();
        var names = formContainer.find('input[name="player_names"]').val();
        var nonce = formContainer.find('input[name="golfscore_nonce"]').val();

        console.log('Add Player - sessionId:', sessionId, 'names:', names, 'nonce:', nonce);

        if (!names || !sessionId) {
            console.error('Missing required fields');
            $('#golfscore-add-player-message').html('Error: Missing required information').show();
            return;
        }

        $.ajax({
            type: 'POST',
            url: golfscoreData.ajaxurl,
            data: {
                action: 'golfscore_add_player',
                session_id: sessionId,
                player_names: names,
                nonce: nonce
            },
            success: function(response) {
                console.log('Add Player Response:', response);
                if (response.success) {
                    $('#golfscore-add-player-message').html(response.data.message).show();
                    // Clear input
                    formContainer.find('input[name="player_names"]').val('');
                    // Reload to show added players in score grid
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    console.error('Add Player Error:', response);
                    $('#golfscore-add-player-message').html('Error: ' + (response.data.message || 'Unknown')).show();
                }
            },
            error: function(xhr, status, err) {
                console.error('Add Player AJAX error:', xhr, status, err);
                $('#golfscore-add-player-message').html('AJAX error: ' + err).show();
            }
        });
    }
    
    $(document).on('click', '.golfscore-add-player-btn', handleAddPlayer);

    // Delete player from session
    $(document).on('click', '.golfscore-delete-player-btn', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var playerId = $btn.data('player-id');
        var sessionId = $btn.data('session-id');
        var playerName = $btn.data('player-name') || 'this player';
        var nonce = $('#golfscore-add-player-form').find('input[name="golfscore_nonce"]').val();

        if (!playerId || !sessionId) {
            $('#golfscore-add-player-message').html('Error: Missing player/session info').show();
            return;
        }

        if (!confirm('Delete ' + playerName + ' from this session?')) {
            return;
        }

        $.ajax({
            type: 'POST',
            url: golfscoreData.ajaxurl,
            data: {
                action: 'golfscore_delete_player',
                session_id: sessionId,
                player_id: playerId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#golfscore-add-player-message').html(response.data.message).show();
                    setTimeout(function() { location.reload(); }, 600);
                } else {
                    $('#golfscore-add-player-message').html('Error: ' + (response.data.message || 'Unknown')).show();
                }
            },
            error: function(xhr, status, err) {
                $('#golfscore-add-player-message').html('AJAX error: ' + err).show();
            }
        });
    });

    function saveSingleScore(sessionId, hole, playerId, score) {
        if (!sessionId || !hole || !playerId || isNaN(score) || score <= 0) {
            return;
        }

        $.ajax({
            type: 'POST',
            url: golfscoreData.ajaxurl,
            data: {
                action: 'golfscore_save_single_score',
                session_id: sessionId,
                hole: hole,
                player_id: playerId,
                score: score,
                nonce: golfscoreData.nonce
            },
            success: function(response) {
                if (!response || !response.success) {
                    return;
                }

                // Pull latest shared state right after write for faster cross-device sync.
                if (typeof pollSessionNow === 'function') {
                    pollSessionNow();
                }
            }
        });
    }

    // Handle score button clicks
    $('.golfscore-score-btn').on('click', function(e) {
        e.preventDefault();
        
        var score = parseInt($(this).data('score'), 10);
        var playerId = parseInt($(this).data('player-id'), 10);
        var $form = $('#golfscore-hole-form');
        var sessionId = parseInt($form.find('input[name="session_id"]').val(), 10);
        var hole = parseInt($form.find('input[name="hole"]').val(), 10);
        var $buttons = $(this).closest('.golfscore-score-buttons').find('.golfscore-score-btn');
        var $hiddenInput = $(this).closest('.golfscore-player-input').find('.golfscore-player-score');
        
        // Update button states
        $buttons.removeClass('active');
        $(this).addClass('active');
        
        // Update hidden input value
        $hiddenInput.val(score);

        // Live-update scorecard total for immediate feedback.
        _golfscoreUpdateLiveScorecardTotal($, playerId, score);

        // Persist instantly so other devices can see the new score right away.
        saveSingleScore(sessionId, hole, playerId, score);
    });

    $('#golfscore-hole-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var sessionId = parseInt($form.find('input[name="session_id"]').val(), 10);
        var hole = parseInt($form.find('input[name="hole"]').val(), 10);
        
        $.ajax({
            type: 'POST',
            url: golfscoreData.ajaxurl,
            data: {
                action: 'golfscore_advance_hole',
                session_id: sessionId,
                hole: hole,
                nonce: golfscoreData.nonce
            },
            success: function(response) {
                if (response.success) {
                    var $response = $('#golfscore-response');
                    $response.removeClass('error').addClass('success').text(response.data.message).slideDown();
                    
                    setTimeout(function() {
                        if (response.data.next_hole && parseInt(response.data.next_hole, 10) > 0) {
                            // Navigate without ?hole= so PHP defaults to current_hole
                            // and manualHole stays false on the new page.
                            window.location.href = '?session_id=' + sessionId;
                        } else {
                            $response.slideUp();
                        }
                    }, 1000);
                }
            },
            error: function() {
                $('#golfscore-response').removeClass('success').addClass('error').text('Error saving scores').slideDown();
            }
        });
    });

    // Previous hole navigation
    $('.golfscore-prev-hole').on('click', function(e) {
        e.preventDefault();
        
        var sessionId = $(this).data('session-id');
        var hole = $(this).data('hole');
        
        window.location.href = '?session_id=' + sessionId + '&hole=' + hole;
    });

    function startSessionAutoSync() {
        var $form = $('#golfscore-hole-form');
        if (!$form.length) {
            return;
        }

        var sessionId = parseInt($form.find('input[name="session_id"]').val(), 10);
        var hole = parseInt($form.find('input[name="hole"]').val(), 10);
        if (!sessionId || !hole) {
            return;
        }

        // If the user manually navigated to a specific hole via the scorecard,
        // don't auto-follow the session's current_hole.
        var manualHole = _golfscoreDetectManualHole();

        var lastSignature = null;
        var isPolling = false;

        function pollSession() {
            if (isPolling) {
                return;
            }

            isPolling = true;
            $.ajax({
                type: 'POST',
                url: golfscoreData.ajaxurl,
                data: {
                    action: 'golfscore_poll_session',
                    session_id: sessionId,
                    hole: hole,
                    nonce: golfscoreData.nonce
                },
                success: function(response) {
                    if (!response || !response.success || !response.data) {
                        return;
                    }

                    var data = response.data;

                    // If the session has moved to a different hole, follow it —
                    // but only when the user hasn't manually picked a hole via the scorecard.
                    if (!manualHole && data.current_hole && parseInt(data.current_hole, 10) !== hole) {
                        window.location.href = '?session_id=' + sessionId;
                        return;
                    }

                    if (lastSignature === data.signature) {
                        return;
                    }

                    if (data.hole_scores) {
                        _golfscoreApplyRemoteHoleScores($, data.hole_scores);
                        // Update the scorecard cells for the viewed hole.
                        $.each(data.hole_scores, function(playerId, score) {
                            _golfscoreUpdateLiveScorecardTotal($, parseInt(playerId, 10), score);
                        });
                    }

                    if (data.hole && data.total_holes) {
                        $('.golfscore-progress').text('Hole ' + data.hole + ' of ' + data.total_holes);
                    }

                    lastSignature = data.signature;
                },
                complete: function() {
                    isPolling = false;
                }
            });
        }

        pollSessionNow = pollSession;
        pollSession();
        setInterval(pollSession, 1000);
    }

    startSessionAutoSync();
});

/* Test exports — available in Node/CommonJS environment only */
if (typeof module !== 'undefined') {
    module.exports = {
        detectManualHole: _golfscoreDetectManualHole,
        updateLiveScorecardTotal: _golfscoreUpdateLiveScorecardTotal,
        applyRemoteHoleScores: _golfscoreApplyRemoteHoleScores,
    };
}
