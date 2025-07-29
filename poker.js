var isMyTurn = false;
var timerEndTime = null;
var timerDuration = 20;
var timerInterval = null;
var timerCurrentLeft = null;
var lastTurnEnd = null;

function drawTimerPieSmooth(percent) {
    var canvas = document.getElementById('turn-timer-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var size = canvas.width;
    var center = size/2;
    var radius = size/2 - 4;
    ctx.clearRect(0, 0, size, size);

    // Värv
    var r = percent < 0.5 ? 255 : Math.round(255 * (1 - percent) * 2);
    var g = percent > 0.5 ? 255 : Math.round(255 * percent * 2);
    var b = 0;
    var color = "rgb("+r+","+g+","+b+")";

    // Taust
    ctx.beginPath();
    ctx.arc(center, center, radius, 0, 2 * Math.PI, false);
    ctx.lineWidth = 6;
    ctx.strokeStyle = "#222";
    ctx.stroke();

    // Progress
    ctx.beginPath();
    ctx.arc(center, center, radius, -Math.PI/2, -Math.PI/2 + 2 * Math.PI * percent, false);
    ctx.lineWidth = 6;
    ctx.strokeStyle = color;
    ctx.stroke();

    // Keskmine täisring
    ctx.beginPath();
    ctx.arc(center, center, radius-8, 0, 2 * Math.PI, false);
    ctx.fillStyle = "#222";
    ctx.fill();

    // Tekst
    var textDiv = document.getElementById('turn-timer-text');
    if (textDiv) {
        var sec = Math.ceil(timerDuration * percent);
        textDiv.innerText = sec > 0 ? sec : '';
    }
}

function startTimer(left) {
    stopTimer();
    timerDuration = left;
    timerEndTime = Date.now() + left * 1000;

    function animate() {
        var now = Date.now();
        var ms_left = timerEndTime - now;
        var sec_left = ms_left / 1000;
        var percent = Math.max(0, sec_left / timerDuration);

        drawTimerPieSmooth(percent);

        // Tekstiks õiged sekundid (mitte percent * timerDuration ümardus)
        var textDiv = document.getElementById('turn-timer-text');
        if (textDiv) {
            var sec = Math.ceil(sec_left);
            textDiv.innerText = sec > 0 ? sec : '';
        }

        if (percent > 0) {
            timerInterval = requestAnimationFrame(animate);
        } else {
            drawTimerPieSmooth(0);
            if(typeof isMyTurn !== "undefined" && isMyTurn) {
                $.post("online.php?to=table&table={$table_id}", {move:'timeout'}, function(){updateTable();});
            }
        }
    }
    animate();
}

function stopTimer() {
    if(timerInterval) cancelAnimationFrame(timerInterval);
    timerInterval = null;
    drawTimerPieSmooth(0);
    var textDiv = document.getElementById('turn-timer-text');
    if (textDiv) textDiv.innerText = '';
}

function updateTable() {
	
    var \$raiseInput = $("input[name='raise']");
    var focus = \$raiseInput.length > 0 && document.activeElement === \$raiseInput[0];
    var selStart = \$raiseInput.length > 0 ? \$raiseInput[0].selectionStart : 0;
    var selEnd = \$raiseInput.length > 0 ? \$raiseInput[0].selectionEnd : 0;
    var oldRaise = \$raiseInput.length > 0 ? \$raiseInput.val() : "";

    $.get("online.php?to=table&table={$table_id}&ajax=1", function(data) {
        $("#pokkerlaud").html(data.html);

        setTimeout(function() {
            var \$raise = $("input[name='raise']");
            if (\$raise.length && typeof oldRaise !== "undefined") {
                \$raise.val(oldRaise);
                if (focus) {
                    \$raise[0].focus();
                    \$raise[0].setSelectionRange(selStart, selEnd);
                }
            }
        }, 0);

        $("#pokkerlog").html(data.log);
        if(data.is_my_turn){
          isMyTurn = true;
          if(lastTurnEnd !== data.turn_end) {
              lastTurnEnd = data.turn_end;
              var now = data.server_time || Math.floor(Date.now()/1000);
              var left = Math.max(0, data.turn_end - now);
              startTimer(left);
          }
        } else {
          isMyTurn = false;
          lastTurnEnd = null;
          stopTimer();
        }
    }, "json");
}

$(function(){
    updateTable();
    setInterval(updateTable, 2000);
});
setInterval(function(){
    $.post("online.php?to=table&table={$table_id}", {heartbeat:1});
}, 12000);
