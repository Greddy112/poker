<?php
if ($general != 2) { header('HTTP/1.1 404 Not Found'); exit(); }
require_once('base.php');
class mod extends base_module {
    public $title = 'Pokkerilauad';
    public function gen() {

        list($cnt, $tables) = $this->simple_query("SELECT * FROM pokerTable ORDER BY PT_id DESC", array());
        if ($cnt == 0) {
            $this->body .= "<em>Lauad puuduvad.</em>";
        } else {
            $tbl = array(array('Laua nimi', 'Blind', 'Buy-in', 'Osalejaid', 'Tegevus'));
            foreach ($tables as $row) {
                $players = 0;
                for ($i = 1; $i <= 6; $i++) if ($row["PT_u{$i}user"]) $players++;
                $tbl[] = array(
                    htmlspecialchars($row['PT_name']),
                    number_format($row['PT_blind'], 0, ',', ' ') . " EEK",
                    number_format($row['PT_buyIn'], 0, ',', ' ') . " EEK",
                    $players . "/6",
                    '<a href="online.php?to=table&table=' . $row['PT_id'] . '" class="tavaline">Mine lauda</a>'
                );
            }
            $this->body .= $this->table($tbl, false);
        }
        $this->body .= "<br>";
		$this->back_link = '?to=kasiino';
    }
    private function is_admin($user_id) {
        list($cnt, $rows) = $this->simple_query("SELECT * FROM user_rights WHERE user_id='%d' AND `right`='admin' AND `limit`>0", array($user_id));
        return $cnt > 0;
    }
}
?>
