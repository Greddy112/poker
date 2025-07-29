<?php
if (!isset($general)) $general = 2;
if ($general != 2) { header('HTTP/1.1 404 Not Found'); exit(); }
require_once(__DIR__ . '/../base.php');

class mod extends base_module {
    public $title = 'Pokkerilaud';

    public function gen() {
        $table_id = isset($_GET['table']) ? intval($_GET['table']) : 0;
		$this->back_link = '?to=poker';
        if (!$table_id) { $this->body .= 'Vale laua ID!'; return; }

        // --- AJAX päring ---
        if (isset($_GET['ajax'])) {
            list($cnt, $tables) = $this->simple_query("SELECT * FROM pokerTable WHERE PT_id='%d'", array($table_id));
            if ($cnt == 0) { echo json_encode(['err'=>'Laua ID vale']); exit(); }
            $table = $tables[0];
            $userSlot = $this->findUserSlot($table, $this->user_id);
            $state = $table['PT_table'] ? json_decode($table['PT_table'], true) : [];
            $active = $this->getActivePlayers($table);

            $now = time();
            if (count($active) >= 2 && (!$state || empty($state['started']) || !$state['started'])) {
                if (isset($state['winner']) && isset($state['last_end']) && ($now - $state['last_end'] < 30)) {
                    $state['log'][] = ['sys', 'Uus käsi algab '.(30 - ($now - $state['last_end'])).' sek pärast.'];
                } else if (!isset($state['winner']) || !isset($state['last_end']) || ($now - $state['last_end'] >= 30)) {
                    $state = $this->new_game(array_keys($active), $table, $state);
                    $state['log'][] = ['sys', 'Uus käsi alustatud!'];
                    $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table['PT_id']));
                }
            }
            if (count($active) < 2 && ($state && !empty($state['started']))) {
                $state['started'] = false;
                $state['log'][] = ['sys', 'Vähem kui 2 mängijat lauas, mäng peatatud!'];
                $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table['PT_id']));
            }

$html = $this->renderTableHTML($table, $state, $userSlot);
$turn_left = max(0, ($state['turn_time']??0) - time());
$is_my_turn = ($userSlot && ($state['turn']??0) == $userSlot && !isset($state['winner']) && $state['started']);
$log_html = $this->render_log($state, $table);


$turn_end = isset($state['turn_time']) ? intval($state['turn_time']) : 0;
$turn_duration = 20;
$turn_start = $turn_end ? $turn_end - $turn_duration : 0;

echo json_encode([
    'html' => $html,
    'pot'  => $state['pot']??0,
    'turn_left' => $turn_left,
    'is_my_turn' => $is_my_turn,
    'stage' => $state['stage']??0,
    'log' => $log_html,
    'turn_start' => $turn_start,
    'turn_end'   => $turn_end,
    'server_time' => time(),
]);
exit();

        }

$table = $this->getTable($table_id);
$state = $table['PT_table'] ? json_decode($table['PT_table'], true) : [];
$userSlot = $this->findUserSlot($table, $this->user_id);

// --- HEARTBEAT käsitlemine ---
if (isset($_POST['heartbeat']) && $userSlot) {
    $this->simple_query2("UPDATE pokerTable SET PT_u{$userSlot}last='%d' WHERE PT_id='%d'", array(time(), $table_id));
    exit();
}

// --- ISTUMISEL uuenda uuendamoine ---
if (isset($_POST['sit']) && !$userSlot) {
    $slot = intval($_POST['sit']);

    if ($slot >= 1 && $slot <= 6 && !$table["PT_u{$slot}user"]) {
        // ... raha maha kasutajalt jne ...
        $this->simple_query2("UPDATE users SET money = money - '%d' WHERE id='%d'", array($table['PT_buyIn'], $this->user_id));
        $this->simple_query2("UPDATE pokerTable SET PT_u{$slot}user='%d', PT_u{$slot}money='%d', PT_u{$slot}status='playing', PT_u{$slot}last='%d' WHERE PT_id='%d'", array($this->user_id, $table['PT_buyIn'], time(), $table_id));
        $this->msg[] = "Oled istunud lauda!";
        
        // kui lauas on nüüd 2 või enam mängijat, alusta uue käega ***
        $table = $this->getTable($table_id); // värskenda tabelit
        $active = [];
        for ($i=1; $i<=6; $i++) if ($table["PT_u{$i}user"]) $active[$i] = $table["PT_u{$i}user"];
        if (count($active) >= 2) {
            // ALUSTA!
            $state = $this->new_game(array_keys($active), $table);
            $state['log'][] = ['sys', 'Uus käsi alustatud! (mängija liitus)'];
            $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table_id));
        } else {
            // Mäng peatub klauas on vähem kui 2 mängijat
            $state = [];
            $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table_id));
        }
        
        $this->redirect('online.php?to=table&table=' . $table_id);
        return;
    }
}

// --- AUTOMAATNE AFK eemaldus ---
$kustutatud = false;
for ($i = 1; $i <= 6; $i++) {
    $uid = $table["PT_u{$i}user"];
    if ($uid) {
        $last = intval($table["PT_u{$i}last"]);
        if ($last < time() - 35) { // 35 sek AFK
            $money_left = $table["PT_u{$i}money"];
            if ($money_left > 0) {
                $this->simple_query2("UPDATE users SET money = money + '%d' WHERE id='%d'", array($money_left, $uid));
            }
            $this->simple_query2("UPDATE pokerTable SET PT_u{$i}user=NULL, PT_u{$i}money=0, PT_u{$i}status=NULL, PT_u{$i}last=0 WHERE PT_id='%d'", array($table_id));
            if ($state && $state['started']) {
                if (!isset($state['left_players'])) $state['left_players'] = [];
                if (!in_array($i, $state['left_players'])) $state['left_players'][] = $i;
            }
            if (isset($state['log']) && is_array($state['log'])) {
                $uname = $this->get_user_name($uid);
                $state['log'][] = [$uname, 'automaatselt eemaldati lauast (inaktiivsus)'];
            }
            $kustutatud = true;
        }
    }
}
if ($kustutatud) {
    $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table_id));
    
    // --- Võitja selgitamine kui alles ainult 1 mängija ---
    $table = $this->getTable($table_id);
    $alles = [];
    for ($i=1; $i<=6; $i++) {
        if ($table["PT_u{$i}user"]) $alles[] = $i;
    }
    if (count($alles) == 1 && $state && !isset($state['winner']) && $state['started']) {
        $winner_slot = $alles[0];
        $state['winner'] = $winner_slot;
        $state['started'] = false;
        $state['last_end'] = time();
        $pot = $state['pot'] ?? 0;
        $stack = $state['stack'] ?? [];
        $stack[$winner_slot] = ($stack[$winner_slot] ?? 0) + $pot;
        $state['pot'] = 0;
        $state['stack'] = $stack;
        $winname = $this->get_user_name($table["PT_u".$winner_slot."user"]);
        $state['log'][] = [$winname, 'võitis (kõik teised lahkusid)'];
        $this->simple_query2("UPDATE pokerTable SET PT_u{$winner_slot}money='%d' WHERE PT_id='%d'", array($stack[$winner_slot], $table_id));
        $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table_id));
    }

    // --- Uus mängu algus kui lauas on vähemalt 2 aktiivset mängijat ja mäng on seisnud ---
    $table = $this->getTable($table_id);
    $aktiivsed = [];
    for ($i=1; $i<=6; $i++) {
        if ($table["PT_u{$i}user"]) $aktiivsed[$i] = $table["PT_u{$i}user"];
    }
    if (count($aktiivsed) >= 2) {
        $can_start = false;
        if (!isset($state['started']) || !$state['started']) $can_start = true;
        if (isset($state['winner'])) $can_start = true;
        $now = time();
        $ok = true;
        if (isset($state['last_end'])) {
            if ($now - $state['last_end'] < 30) $ok = false;
        }
        if ($can_start && $ok) {
            $state = $this->new_game(array_keys($aktiivsed), $table, $state);
            $state['log'][] = ['sys', 'Uus käsi alustatud! (AFK eemaldus)'];
            $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table_id));
        }
    }
}


// Tõuse püsti (kui mäng pooleli, loetakse foldiks/lahkuks!)
if (isset($_POST['leave']) && $userSlot) {
    $money_left = $table["PT_u{$userSlot}money"];
    if ($money_left > 0) {
        $this->simple_query2("UPDATE users SET money = money + '%d' WHERE id='%d'", array($money_left, $this->user_id));
    }
    $this->simple_query2("UPDATE pokerTable SET PT_u{$userSlot}user=NULL, PT_u{$userSlot}money=0, PT_u{$userSlot}status=NULL WHERE PT_id='%d'", array($table_id));

    // Märgi, et mängija on left_players sees kuni uue käeni
    if ($state && $state['started']) {
        if (!isset($state['left_players'])) $state['left_players'] = [];
        if (!in_array($userSlot, $state['left_players'])) $state['left_players'][] = $userSlot;

        // Kontrolli kas lauas on veel vaid 1 mängija
        $alles = [];
        foreach ($table as $k => $v) {
            if (preg_match('/PT_u(\d)user$/', $k, $m) && $v && !in_array(intval($m[1]), $state['left_players'] ?? [])) {
                $alles[] = intval($m[1]);
            }
        }
        if (count($alles) == 1) {
            $winner_slot = $alles[0];
            $state['winner'] = $winner_slot;
            $state['started'] = false;
            $state['last_end'] = time();
            $pot = $state['pot'] ?? 0;
            $stack = $state['stack'] ?? [];
            $stack[$winner_slot] = ($stack[$winner_slot] ?? 0) + $pot; // LIHTSALT POT!
            $state['pot'] = 0;
            $state['stack'] = $stack;
            $winname = $this->get_user_name($table["PT_u".$winner_slot."user"]);
            $state['log'][] = [$winname, 'võitis (kõik teised lahkusid)'];
            $this->simple_query2("UPDATE pokerTable SET PT_u{$winner_slot}money='%d' WHERE PT_id='%d'", array($stack[$winner_slot], $table_id));
        }
        $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($state), $table_id));
    }
    $this->msg[] = "Tõusid laualt, raha kanti tagasi!";
    $this->redirect('online.php?to=table&table=' . $table_id);
    return;
}


        // --- Pokkeri actionid POST ---
        if (isset($_POST['move']) && $userSlot) {
            $table = $this->getTable($table_id);
            $state = $table['PT_table'] ? json_decode($table['PT_table'], true) : [];
            $res = $this->gameMove($userSlot, $_POST, $table, $state);
            if (is_string($res)) $this->error[] = $res;
            else {
                foreach($res['stack'] as $slot => $newStack) {
                    $this->simple_query2("UPDATE pokerTable SET PT_u{$slot}money='%d' WHERE PT_id='%d'", array($newStack, $table_id));
                }
                $this->simple_query2("UPDATE pokerTable SET PT_table='%s' WHERE PT_id='%d'", array(json_encode($res), $table_id));
                foreach($res['stack'] as $slot => $newStack) {
                    if ($newStack <= 0 && $table["PT_u{$slot}user"]) {
                        $this->simple_query2("UPDATE pokerTable SET PT_u{$slot}user=NULL, PT_u{$slot}money=0, PT_u{$slot}status=NULL WHERE PT_id='%d'", array($table_id));
                    }
                }
            }
            $this->redirect('online.php?to=table&table=' . $table_id);
            return;
        }


        $this->body .= '<div id="pokkerlaud">'.$this->renderTableHTML($table, $state, $userSlot).'</div>';
        $this->body .= '<div id="pokkerlog" class="pokker-log">'.$this->render_log($state, $table).'</div>';
    }

private function renderTableHTML($table, $state, $userSlot) {
$buyin = $table['PT_buyIn'] ?? 0;
$laudKlass = 'pokertable-oval';
$html = '<div class="'.$laudKlass.'" style="position:relative;">';
$html .= '<div class="pokertable-inner'.($buyin >= 50000 ? ' blue' : '').'"></div>';

// Lisa logo jms kohe pärast seda!
$html .= '<div class="pokertable-logo">
    <span class="pix">Pix</span><span class="play">Play</span>
    <span class="poker">pokker</span>
</div>';


    // Määra sloti seis
    $folded = [];
    $waiting = [];
    $playing = [];
    if ($state && isset($state['players'])) {
        $players = $state['players'];
        $player_uids = [];
        foreach ($players as $slot) {
            if (!empty($table["PT_u{$slot}user"])) {
                $player_uids[] = $table["PT_u{$slot}user"];
            }
        }
        for ($i = 1; $i <= 6; $i++) {
            $uid = $table["PT_u{$i}user"];
            if (!$uid) continue;

            // Kui slot pole selles käes sees, aga on kasutaja olemas = FOLDIS
            if (!in_array($i, $players)) {
                $folded[$i] = true;
            } else {
                $playing[$i] = true;
            }
        }
    }

    // SLOTIDE RENDER
    for ($i = 1; $i <= 6; $i++) {
        $uid = $table["PT_u{$i}user"];
        $money = $table["PT_u{$i}money"];
        $class = "pokertable-slot slot$i";
        if ($uid == $this->user_id) $class .= ' mine';
        if (!$uid) $class .= ' empty';
        if (isset($folded[$i])) $class .= ' folded';

        $html .= '<div class="'.$class.'">';
        if ($uid) {
            $mine = ($uid == $this->user_id) ? " <span style='color:#0a5'></span>" : "";
            $html .= "<div class='pokertable-name'>".htmlspecialchars($this->get_user_name($uid)).$mine."</div>";
            $html .= "<div class='pokertable-money'>".number_format($money,0,',',' ')." EEK</div>";

            if (isset($folded[$i])) {
                $html .= "<div style='color:#b32;'>foldis</div>";
            }

            if ($state && $state['started']) {
                if (($state['dealer']??0)==$i)      $html .= '<div class="dealer-label">D</div>';
                if (($state['small_blind']??0)==$i) $html .= '<div class="sb-bb-label sb-label">SB</div>';
                if (($state['big_blind']??0)==$i)   $html .= '<div class="sb-bb-label bb-label">BB</div>';
            }
            if ($state && $state['started'] && isset($state['hands'][$i])) {
                $kaardid = $state['hands'][$i];
                $show = false;
                if ($uid == $this->user_id) $show = true;
                if (isset($state['winner'])) $show = true;
                $html .= '<div class="pokertable-mycards">';
                // Ära näita kaarte, kui folded
                if ($show && !isset($folded[$i])) {
                    $k_size = ($uid == $this->user_id) ? 'lg' : 'xs';
                    foreach($kaardid as $k) $html .= $this->render_card($k, true, $k_size);
                } else {
                    $html .= $this->render_card('', false, 'xs');
                    $html .= $this->render_card('', false, 'xs');
                }
                $html .= '</div>';
            }
        } else {
            if (!$userSlot) {
                $html .= "<form method='post' style='margin:0;'><input type='hidden' name='sit' value='$i'><button class='pokertable-btn'>Istun siia</button></form>";
            } else {
                $html .= "[Vaba]";
            }
        }
        $html .= '</div>';
    }

    // LAUD
    $html .= '<div class="pokertable-board">';
    if ($state && isset($state['board'])) {
        foreach($state['board'] as $card) $html .= $this->render_card($card, true, 'md');
    }
    $html .= '</div>';

    // POT
    $show_pot = isset($state['started']) && $state['started'] && isset($state['players']) && count($state['players']) > 0;
    $pot_amt = $state['pot']??0;
    $html .= '</div>'; // pokertable-oval lõppeb

    if ($show_pot && $pot_amt > 0) {
        $html .= '<div class="pokertable-pot">Pot: '.number_format($pot_amt,0,',',' ').' EEK</div>';
    }

    // Nupud
    $html .= '<div class="pokertable-controls" style="margin-top:12px;text-align:center">';

    if ($userSlot && isset($state['turn']) && $state['turn'] == $userSlot && !isset($state['winner'])) {
        $call_amt = max($state['bets']) - $state['bets'][$userSlot];
        $call_amt = max(0, $call_amt);
        $html .= '<div style="color:#b51a1a;font-size:20px;margin:8px 0;position:relative;min-height:54px;">
            Sinu käik! 
            <span style="display:inline-block;vertical-align:middle;position:relative;width:48px;height:48px;">
                <canvas id="turn-timer-canvas" width="48" height="48" style="display:block;"></canvas>
                <span id="turn-timer-text"
                    style="position:absolute;left:0;top:0;width:48px;height:48px;text-align:center;line-height:48px;
                        font-size:22px;font-weight:bold;color:#fff;pointer-events:none;">
                </span>
            </span>
        </div>';

        $html .= '<form method="post" style="display:inline-block;margin-top:4px;">';
        if ($call_amt == 0) {
            $html .= '<button name="move" value="check" class="pokertable-btn" style="background:#35c247;">Check</button> ';
        } else {
            $html .= '<button name="move" value="call" class="pokertable-btn" style="background:#35c247;">Call ('.number_format($call_amt,0,',',' ').' EEK)</button> ';
        }
        $html .= '<input type="number" name="raise" min="'.($state['min_raise']??0).'" max="'.$table["PT_u{$userSlot}money"].'" value="'.($state['min_raise']??0).'" style="width:64px;"> ';
        $html .= '<button name="move" value="raise" class="pokertable-btn" style="background:#3791d6;">Raise (min '.number_format($state['min_raise']??0,0,',',' ').')</button>';
        $html .= '<button name="move" value="fold" class="pokertable-btn" style="background:#e03636;">Fold</button> ';
        $html .= '</form>';
    } else if (!empty($state['started']) && !isset($state['winner'])) {
        $html .= '<div style="color:#333;">Oota teisi mängijaid või nende käiku...</div>';
    }
    if (isset($state['winner'])) {
        $winName = $this->get_user_name($table["PT_u".$state['winner']."user"]);
        $winMoney = $table["PT_u".$state['winner']."money"];
        $html .= "<div style='color:#555;font-size:15px;'>Uus käsi algab 30 sek pärast</div>";
    }
    $html .= '</div>';
    if ($userSlot) {
        $html .= '<div style="text-align:center;margin-top:15px;">
            <form method="post" style="display:inline;">
                <button name="leave" class="pokertable-btn" style="background:#e03636;">Tõuse püsti</button>
            </form>
        </div>';
    }
    return $html;
}


private function render_card($card, $show=true, $size='md') {
    $base = "images/cards/"; // ainult üks kataloog!
    $cls = "card";
    if ($size == 'md') $cls .= " card-md";
    else if ($size == 'lg') $cls .= " card-lg";
    else $cls .= " card-xs";
    if (!$show || !$card) return '<img src="'.$base.'back.png" class="'.$cls.'" />';
    list($val, $suit) = explode('-', $card);
    $valmap = ['A'=>'ace','K'=>'king','Q'=>'queen','J'=>'jack','10'=>'10','9'=>'9','8'=>'8','7'=>'7','6'=>'6','5'=>'5','4'=>'4','3'=>'3','2'=>'2',];
    $suitmap = ['h'=>'hearts','d'=>'diamonds','c'=>'clubs','s'=>'spades',];
    $v = isset($valmap[$val]) ? $valmap[$val] : $val;
    $s = isset($suitmap[$suit]) ? $suitmap[$suit] : $suit;
    $fname = "{$v}_of_{$s}.png";
    return '<img src="'.$base.$fname.'" class="'.$cls.'" />';
}

    private function render_log($state, $table) {
        if (!isset($state['log']) || !is_array($state['log'])) return '';
        $html = '';
        foreach (array_slice($state['log'], -12) as $row) {
            if ($row[0]==='sys') $html .= '<div class="pokker-log-row pokker-log-sys">'.$row[1].'</div>';
            else $html .= '<div class="pokker-log-row"><span class="pokker-log-user">'.htmlspecialchars($row[0]).'</span> '.$row[1].'</div>';
        }
        return $html;
    }

    private function findUserSlot($table, $user_id) {
        for ($i = 1; $i <= 6; $i++) if ($table["PT_u{$i}user"] == $user_id) return $i;
        return 0;
    }
    private function countPlayers($table) {
        $cnt = 0; for ($i = 1; $i <= 6; $i++) if ($table["PT_u{$i}user"]) $cnt++; return $cnt;
    }
    private function getActivePlayers($table) {
        $res = [];
        for ($i=1; $i<=6; $i++) if ($table["PT_u{$i}user"]) $res[$i] = $table["PT_u{$i}user"];
        return $res;
    }
    private function getTable($table_id) {
        list($cnt, $tables) = $this->simple_query("SELECT * FROM pokerTable WHERE PT_id='%d'", array($table_id));
        return $tables[0];
    }
public function get_user_name($user_id) {
    list($cnt, $rows) = $this->simple_query("SELECT username FROM users WHERE id='%d'", array($user_id));
    return $cnt ? $rows[0]['username'] : 'Tundmatu';
}


 // ... kogu muu kood jääb samaks, välja arvatud need võtmekohad ...

private function new_game($slots, $table, $prev_state = null) {
    $lastDealer = 0;
    if ($prev_state && isset($prev_state['dealer'])) {
        $lastDealer = $prev_state['dealer'];
    }

    // Leia uue käe dealer slot
    if ($lastDealer && in_array($lastDealer, $slots)) {
        $idx = array_search($lastDealer, $slots);
        $dealerSlot = $slots[ ($idx+1) % count($slots) ];
    } else {
        $dealerSlot = $slots[0];
    }

    if (count($slots)==2) {
        $dealerIdx = array_search($dealerSlot, $slots);
        $sbSlot = $dealerSlot;
        $bbSlot = $slots[ ($dealerIdx+1)%2 ];
    } else {
        $dealerIdx = array_search($dealerSlot, $slots);
        $sbSlot = $slots[ ($dealerIdx+1) % count($slots) ];
        $bbSlot = $slots[ ($dealerIdx+2) % count($slots) ];
    }

    $suits = ['c','h','s','d']; $vals = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
    $deck = []; foreach($suits as $s) foreach($vals as $v) $deck[] = $v.'-'.$s;
    shuffle($deck);
    $hands = [];
    foreach ($slots as $slot) $hands[$slot] = [array_pop($deck), array_pop($deck)];
    $small_blind = $table['PT_blind'];
    $big_blind = $table['PT_blind']*2;

    // --- Stack ja PT_uXmoney korrektselt maha ---
    $stack = [];
    foreach ($slots as $slot) $stack[$slot] = $table["PT_u{$slot}money"];

    // MAHA KOHE
    $stack[$sbSlot] -= $small_blind;
    $stack[$bbSlot] -= $big_blind;

    // KIRJUTA KOHE UUS SUMMA ANDMEBAASI
    $this->simple_query2("UPDATE pokerTable SET PT_u{$sbSlot}money='%d' WHERE PT_id='%d'", array($stack[$sbSlot], $table['PT_id']));
    $this->simple_query2("UPDATE pokerTable SET PT_u{$bbSlot}money='%d' WHERE PT_id='%d'", array($stack[$bbSlot], $table['PT_id']));

    $pot = $small_blind + $big_blind;
    $bets = [];
    foreach ($slots as $slot) $bets[$slot] = 0;
    $bets[$sbSlot] = $small_blind;
    $bets[$bbSlot] = $big_blind;

    $pending = [];
    if (count($slots) == 2) {
        $pending[] = $sbSlot;
        $pending[] = $bbSlot;
    } else {
        $dealerIdx = array_search($dealerSlot, $slots);
        $start = ($dealerIdx+3)%count($slots);
        for ($i=0; $i<count($slots)-1; $i++) {
            $pending[] = $slots[ ($start + $i) % count($slots) ];
        }
    }

return [
    'started' => true,
    'players' => $slots,
    'hands'   => $hands,
    'board'   => [],
    'pot'     => $pot,
    'stack'   => $stack,
    'bets'    => $bets,
    'turn'    => $pending[0],
    'stage'   => 0,
    'min_raise' => $big_blind,
    'turn_time' => time() + 20,
    'dealer'      => $dealerSlot,
    'small_blind' => $sbSlot,
    'big_blind'   => $bbSlot,
    'winner'      => null,
    'last_raiser' => $bbSlot, // <-- alguses bb on viimane tõstja
    'pending'     => $pending,
    'log'         => [],
    'left_players'=> []
];

}


// Lisa see uus abifunktsioon oma klassi sisse:
private function build_pending($players, $last_raiser, $stack) {
    $pending = [];
    $idx = array_search($last_raiser, $players);
    for ($i = 1; $i < count($players); $i++) {
        $p = $players[($idx + $i) % count($players)];
        if ($stack[$p] > 0) $pending[] = $p;
    }
    return $pending;
}

// Uus betting_finished funktsioon (kasuta ka pending'ut argumendina!):
private function betting_finished($bets, $players, $stage = 0, $cnt = 2, $pending = []) {
    if (count($players) < 2) return true;
    if (!empty($pending)) return false; // ring pole lõppenud, kui on veel pendingus
    // Preflop HU erand:
    if ($cnt == 2 && $stage == 0) {
        return count(array_unique($bets)) == 1 && min($bets) == max($bets);
    }
    // Kõik betid võrdsed?
    $ref = null;
    foreach ($players as $p) {
        if ($ref === null) $ref = $bets[$p];
        if ($bets[$p] != $ref) return false;
    }
    return true;
}

// Siin on parendatud gameMove (kogu funktsioon):
private function gameMove($userSlot, $post, $table, $state) {
    if (!$state || !$state['started']) return $state;
    $move = $post['move'];
    $players = $state['players'];
    $stack = $state['stack'];
    $bets = $state['bets'];
    $pot = $state['pot'];
    $turn = $state['turn'];
    $min_raise = $state['min_raise'];
    $stage = $state['stage'];
    $last_raiser = $state['last_raiser'];
    $hands = $state['hands'];
    $board = $state['board'];
    $log = isset($state['log']) && is_array($state['log']) ? $state['log'] : [];
    $pending = $state['pending'];
    $uname = $this->get_user_name($table["PT_u".$userSlot."user"]);

    if ($turn != $userSlot && $move != 'timeout') return "Pole sinu käik!";
    if ($move == 'timeout') {
        $move = ($bets[$userSlot] == max($bets)) ? 'check' : 'fold';
        $log[] = [$uname, 'ei teinud otsust – '.($move=='check'?'check':'fold')];
    }

    if ($move == 'fold') {
        $log[] = [$uname, 'foldis'];
        $players = array_values(array_diff($players,[$userSlot]));
        unset($bets[$userSlot]);
        unset($stack[$userSlot]);
        unset($hands[$userSlot]);
        $pending = array_values(array_diff($pending, [$userSlot]));
        if (count($players)==1) {
            $state['winner']=$players[0];
            $slot = $players[0];
            $stack[$slot] += $pot + $bets[$slot];
            $pot = 0;
            $state['pot'] = $pot;
            $state['stack'] = $stack;
            $state['last_end'] = time();
            $log[] = [$this->get_user_name($table["PT_u".$players[0]."user"]), 'võitis (teised foldisid)'];
            $state['started']=false;
            $state['log']=$log;
            return $state;
        }
        $pending = array_values(array_diff($pending, [$userSlot]));
        if (empty($pending)) {
            if ($this->betting_finished($bets, $players, $stage, count($players), $pending)) {
                return $this->betting_advance_stage($state, $table, $players, $stack, $bets, $board, $log, $hands);
            }
        }
        $turn = $pending[0];
        $state['turn_time'] = time() + 20;
    }

if ($move == 'call' || $move == 'check') {
    $call_amt = max($bets) - $bets[$userSlot];
    if ($stack[$userSlot] < $call_amt) $call_amt = $stack[$userSlot];
    $bets[$userSlot] += $call_amt;
    $stack[$userSlot] -= $call_amt;
    $pot += $call_amt;
    $log[] = [$uname, ($move == 'call' ? 'callis' : 'checkis').($call_amt ? ' '.number_format($call_amt,0,',',' ').' EEK' : '')];
    $pending = array_values(array_diff($pending, [$userSlot])); // eemalda käija pendingist
    if (empty($pending)) {
        if ($this->betting_finished($bets, $players, $stage, count($players), $pending)) {
            $state['players'] = $players;
            $state['stack'] = $stack;
            $state['bets'] = $bets;
            $state['pot'] = $pot;
            $state['turn'] = null; // või jäta see isegi andmata
            $state['hands'] = $hands;
            $state['board'] = $board;
            $state['log'] = $log;
            $state['pending'] = $pending;
            return $this->betting_advance_stage($state, $table, $players, $stack, $bets, $board, $log, $hands);
        }
    } else {
        $turn = $pending[0];
        $state['turn_time'] = time() + 20;
    }
}



if ($move == 'raise') {
    $raise_amt = intval($post['raise'] ?? $min_raise);
    if ($raise_amt < $min_raise) $raise_amt = $min_raise;
    if ($stack[$userSlot] < ($raise_amt + max($bets) - $bets[$userSlot])) return "Pole piisavalt raha!";
    $call_amt = max($bets) - $bets[$userSlot];
    $total = $call_amt + $raise_amt;
    if ($stack[$userSlot] < $total) $total = $stack[$userSlot];
    $bets[$userSlot] += $total;
    $stack[$userSlot] -= $total;
    $pot += $total;
    $log[] = [$uname, 'tõstis '.number_format($total,0,',',' ').' EEK'];

    // --- PARANDUS: Pending on kõik teised peale raiser'i, järjekorras ---
    $pending = [];
    $idx = array_search($userSlot, $players);
    for ($i=1; $i < count($players); $i++) {
        $p = $players[($idx + $i) % count($players)];
        if ($stack[$p] > 0) $pending[] = $p;
    }
    $turn = $pending[0] ?? null;
    $state['min_raise'] = $raise_amt;
    $state['last_raiser'] = $userSlot;
    $state['turn_time'] = time() + 20;
}


    // Salvesta kõik seisud
    $state['players'] = $players;
    $state['stack'] = $stack;
    $state['bets'] = $bets;
    $state['pot'] = $pot;
    $state['turn'] = $turn;
    $state['hands'] = $hands;
    $state['board'] = $board;
    $state['log'] = $log;
    $state['pending'] = $pending;
    return $state;
}


private function betting_advance_stage($state, $table, $players, $stack, $bets, $board, $log, $hands) {
    $stage = $state['stage'];
    if ($stage==0) {
        $board = $this->draw_cards($board, 3);
        $stage = 1;
        $log[] = ['sys', 'Flop avatud!'];
    }
    elseif ($stage==1) {
        $board = $this->draw_cards($board, 1);
        $stage = 2;
        $log[] = ['sys', 'Turn avatud!'];
    }
    elseif ($stage==2) {
        $board = $this->draw_cards($board, 1);
        $stage = 3;
        $log[] = ['sys', 'River avatud!'];
    }
    elseif ($stage==3) {
        // VÕITJA
        $winners = [];
        $best_rank = -1;
        $best_name = '';
        foreach ($players as $p) {
            $hand_name = $this->get_best_hand_name($hands[$p], $board);
            $rank = $this->hand_rank($hand_name);
            if ($rank > $best_rank) {
                $winners = [$p];
                $best_rank = $rank;
                $best_name = $hand_name;
            } elseif ($rank === $best_rank) {
                $winners[] = $p;
            }
        }
        $winner = $winners[0];
        $stack[$winner] += $state['pot'];
        $state['winner'] = $winner;
        $state['started'] = false;
        $state['hands'] = $hands;
        $state['board'] = $board;
        $state['last_end'] = time();
        $winname = $this->get_user_name($table["PT_u".$winner."user"]);
        $log[] = [
            $winname,
            'võitis ' . number_format($state['pot'],0,',',' ') . ' EEK! <b>' . $best_name . '</b>'
        ];
        $state['log']=$log;
        $state['pot'] = 0;
        $state['stack'] = $stack;
        return $state;
    }

    // Betsid nulliks, potsi ÄRA PUUDUTA!
    foreach ($players as $p) $bets[$p] = 0;

    // ÕIGE PENDING & TURN LOGIKA!
    $pending = [];
    $turn = null;

    if (count($players) == 2) {
        // Heads-up: preflop SB alustab, pärast floppi BB alustab
        $dealer = $state['dealer'];
        $sb = $dealer;
        $bb = $players[($players[0] == $sb) ? 1 : 0];
        if ($stage == 1 || $stage == 2 || $stage == 3) {
            // Flop, turn, river: BB alustab
            if ($stack[$bb] > 0) $pending[] = $bb;
            if ($stack[$sb] > 0) $pending[] = $sb;
            $turn = $pending[0];
        } else {
            // Preflop: SB alustab
            if ($stack[$sb] > 0) $pending[] = $sb;
            if ($stack[$bb] > 0) $pending[] = $bb;
            $turn = $pending[0];
        }
    } else {
        // 3+ mängijat: flopist alates alustab _esimene elus mängija pärast dealerit_
        $dealer = $state['dealer'];
        $idx = array_search($dealer, $players);
        $count = count($players);
        for ($i = 1; $i <= $count; $i++) {
            $slot = $players[($idx+$i)%$count];
            if ($stack[$slot] > 0) $pending[] = $slot;
        }
        $turn = $pending[0];
    }

    $state['stage'] = $stage;
    $state['board'] = $board;
    $state['bets'] = $bets;
    $state['turn'] = $turn;
    $state['pending'] = $pending;
    $state['log'] = $log;
    $state['stack'] = $stack;
    $state['turn_time'] = time() + 20;
    // POT jääb samaks!!!
    return $state;
}



    private function pending_from_next($players, $after, $stack) {
        $pending = [];
        $idx = array_search($after, $players);
        for ($i=1; $i<count($players); $i++) {
            $p = $players[($idx+$i)%count($players)];
            if ($stack[$p]>0) $pending[] = $p;
        }
        return $pending;
    }

    private function draw_cards($old_board, $count) {
        $board = $old_board ? $old_board : [];
        $all = [];
        $suits = ['c','h','s','d']; $vals = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
        foreach($suits as $s) foreach($vals as $v) $all[] = $v.'-'.$s;
        shuffle($all);
        foreach ($all as $card) {
            if (!in_array($card, $board) && count($board)<5)
                $board[] = $card;
            if (count($board) >= count($old_board)+$count) break;
        }
        return $board;
    }
	
    private function hand_rank($name) {
        $order = ['Kõrge kaart', 'Paar', 'Kaks paari', 'Kolmik', 'Rida', 'Mast', 'Maja', 'Nelik', 'Mastirida'];
        return array_search($name, $order);
    }

    private function get_hand_name($cards) {
        $values = [];
        $suits = [];
        foreach ($cards as $c) {
            list($v, $s) = explode('-', $c);
            $values[] = $v;
            $suits[] = $s;
        }
        $vals_order = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        $vals_ranks = array_flip($vals_order);
        $values_numeric = array_map(function($v) use ($vals_ranks) { return $vals_ranks[$v]; }, $values);
        sort($values_numeric);

        // Flush?
        $flush = count(array_unique($suits)) == 1;

        // Pairid, kolmikut jne
        $counts = array_count_values($values);
        $count_vals = array_values($counts);
        sort($count_vals);

        // Rida (straight)?
        $is_straight = false;
        if (count($values_numeric) >= 5) {
            $uniq = array_values(array_unique($values_numeric));
            for ($i = 0; $i <= count($uniq) - 5; $i++) {
                if (
                    $uniq[$i+4] - $uniq[$i] == 4 &&
                    $uniq[$i+1] - $uniq[$i] == 1 &&
                    $uniq[$i+2] - $uniq[$i+1] == 1 &&
                    $uniq[$i+3] - $uniq[$i+2] == 1 &&
                    $uniq[$i+4] - $uniq[$i+3] == 1
                ) $is_straight = true;
            }
            // Erand: A2345 (A=12, 0,1,2,3,12)
            if (in_array(12, $uniq) && in_array(0, $uniq) && in_array(1, $uniq) && in_array(2, $uniq) && in_array(3, $uniq)) $is_straight = true;
        }

        if ($flush && $is_straight) return "Mastirida";
        if ($count_vals == [1,4]) return "Nelik";
        if ($count_vals == [2,3]) return "Maja";
        if ($flush) return "Mast";
        if ($is_straight) return "Rida";
        if ($count_vals == [1,1,3]) return "Kolmik";
        if ($count_vals == [1,2,2]) return "Kaks paari";
        if ($count_vals == [1,1,1,2]) return "Paar";
        return "Kõrge kaart";
    }

    private function get_best_hand_name($hand, $board) {
        $all = array_merge($hand, $board);
        $combs = $this->combinations($all, 5);
        $order = ['Kõrge kaart', 'Paar', 'Kaks paari', 'Kolmik', 'Rida', 'Mast', 'Maja', 'Nelik', 'Mastirida'];
        $best = null;
        $best_idx = -1;
        foreach ($combs as $combo) {
            $name = $this->get_hand_name($combo);
            $idx = array_search($name, $order);
            if ($idx > $best_idx) {
                $best = $name;
                $best_idx = $idx;
            }
        }
        return $best;
    }

    private function combinations($arr, $n) {
        $result = [];
        $recurse = function($arr, $n, $prefix = []) use (&$result, &$recurse) {
            if ($n == 0) {
                $result[] = $prefix;
                return;
            }
            for ($i = 0; $i <= count($arr) - $n; $i++) {
                $recurse(array_slice($arr, $i+1), $n-1, array_merge($prefix, [$arr[$i]]));
            }
        };
        $recurse($arr, $n);
        return $result;
    }

}
?>