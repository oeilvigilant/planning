<?php
/**
 * Tâches planifiées (toutes les 5 min) : rappels agents, alerte « poste non pris »,
 * fin de service oubliée, rapport hebdo client, purge RGPD.
 * Déclenchées par cron.php (tâche planifiée LWS / cron-job.org) et, en secours,
 * par les visites du site (au plus une fois toutes les 5 min).
 * Chaque envoi est protégé par ptUneFois() : jamais deux fois le même rappel.
 */

function ptExecuterTaches(?int $now = null): array {
    $now = $now ?? time();
    $db = getDB();
    $log = [];
    if (!(int)$db->query("SELECT GET_LOCK('ovpt_taches', 0)")->fetchColumn()) return ['déjà en cours'];
    try {
        setParam('pointage_taches_ts', (string)$now);
        $avant = max(0, (int)getParam('pointage_rappel_avant_min', '15'));
        $nonPris = max(5, (int)getParam('pointage_alerte_non_pris_min', '15'));

        // 1. Vacations du planning : rappel, retard, poste non pris
        if (ptHasPlanning()) {
            $st = $db->prepare("SELECT pl.id, pl.agent_id, pl.date_travail, pl.heure_debut, pl.heure_fin,
                    m.id AS mission_id, m.nom AS site_nom, a.prenom, a.nom
                FROM planning_lignes pl
                JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
                JOIN missions m ON m.id = pv.mission_id AND m.actif = 1
                JOIN agents a ON a.id = pl.agent_id AND a.actif = 1
                WHERE pl.date_travail BETWEEN ? AND ?");
            $st->execute([date('Y-m-d', $now - 86400), date('Y-m-d', $now + 86400)]);
            $chk = $db->prepare("SELECT COUNT(*) FROM pt_services WHERE agent_id = ? AND statut <> 'annule' AND debut_retenu BETWEEN ? AND ?");
            foreach ($st->fetchAll() as $v) {
                $d = strtotime($v['date_travail'] . ' ' . $v['heure_debut']);
                $f = strtotime($v['date_travail'] . ' ' . $v['heure_fin']);
                if ($f <= $d) $f += 86400;
                if ($now < $d - $avant * 60 || $now >= $f) continue;
                $chk->execute([$v['agent_id'], date('Y-m-d H:i:s', $d - 3 * 3600), date('Y-m-d H:i:s', $f)]);
                if ($chk->fetchColumn()) continue;   // déjà pointé
                $h = date('H\hi', $d);
                if ($now < $d) {
                    if (ptUneFois('rappel_' . $v['id'] . '_' . $d)) {
                        ptNotifierAgent((int)$v['agent_id'], 'Votre vacation commence à ' . $h, $v['site_nom'] . ' — pensez à pointer votre prise de service.');
                        $log[] = "rappel {$v['prenom']} {$v['nom']} ($h)";
                    }
                } else {
                    if ($now >= $d + 5 * 60 && ptUneFois('retard_' . $v['id'] . '_' . $d)) {
                        ptNotifierAgent((int)$v['agent_id'], 'Vous n\'avez pas pointé', 'Votre vacation ' . $v['site_nom'] . ' a commencé à ' . $h . '. Pointez dès votre arrivée.', 'app.php', true);
                        $log[] = "retard agent {$v['prenom']} {$v['nom']}";
                    }
                    if ($now >= $d + $nonPris * 60 && ptUneFois('nonpris_' . $v['id'] . '_' . $d)) {
                        ptNotifierResponsables('Poste non pris : ' . $v['site_nom'],
                            $v['prenom'] . ' ' . $v['nom'] . ' devait commencer à ' . $h . ' et n\'a pas pointé.',
                            'admin/index.php', true, true);
                        $log[] = "poste non pris {$v['site_nom']} ($h)";
                    }
                }
            }
        }

        // 2. Services en cours : fin oubliée
        $ouverts = $db->query("SELECT s.id, s.agent_id, s.prevu_fin, s.debut_reel, a.prenom, a.nom, m.nom AS site_nom
            FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
            WHERE s.fin_retenu IS NULL AND s.statut <> 'annule'")->fetchAll();
        foreach ($ouverts as $s) {
            if ($s['prevu_fin']) {
                $f = strtotime($s['prevu_fin']);
                if ($now >= $f + 15 * 60 && ptUneFois('finoubli_' . $s['id'])) {
                    ptNotifierAgent((int)$s['agent_id'], 'Pensez à pointer votre fin de service', 'Votre vacation ' . $s['site_nom'] . ' devait finir à ' . date('H\hi', $f) . '.');
                    $log[] = "fin oubliée (agent) {$s['prenom']} {$s['nom']}";
                }
                if ($now >= $f + 60 * 60 && ptUneFois('finresp_' . $s['id'])) {
                    ptNotifierResponsables('Fin de service non pointée', $s['prenom'] . ' ' . $s['nom'] . ' (' . $s['site_nom'] . ') est toujours « en service » 1 h après la fin prévue.', 'admin/pointages.php?statut=en_cours');
                    $log[] = "fin oubliée (resp) {$s['prenom']} {$s['nom']}";
                }
            } elseif ($now >= strtotime($s['debut_reel']) + 12 * 3600 && ptUneFois('long_' . $s['id'])) {
                ptNotifierAgent((int)$s['agent_id'], 'Service en cours depuis 12 h', 'Avez-vous oublié de pointer votre fin de service ?');
                ptNotifierResponsables('Service ouvert depuis 12 h', $s['prenom'] . ' ' . $s['nom'] . ' (' . $s['site_nom'] . ') n\'a pas pointé sa fin.', 'admin/pointages.php?statut=en_cours');
                $log[] = "service long {$s['prenom']} {$s['nom']}";
            }
        }

        // 2 bis. Rondes en retard : sur les sites avec une fréquence de ronde, chaque agent en
        // service doit terminer une ronde complète toutes les N minutes (+ marge).
        $marge = max(0, (int)getParam('pointage_ronde_marge_min', '15'));
        $rs = $db->query("SELECT s.id, s.agent_id, s.mission_id, s.debut_reel, a.prenom, a.nom, m.nom AS site_nom, m.pt_ronde_freq_min AS freq
            FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
            WHERE s.fin_retenu IS NULL AND s.statut <> 'annule' AND m.pt_ronde_freq_min > 0 AND m.pt_ronde_mode <> 'aucune'
              AND EXISTS (SELECT 1 FROM pt_points p WHERE p.mission_id = m.id AND p.actif = 1)")->fetchAll();
        foreach ($rs as $s) {
            $freq = (int)$s['freq'] * 60;
            $etat = ptEtatRonde((int)$s['mission_id'], (int)$s['agent_id'], $now);
            $ref = strtotime($s['debut_reel']);
            if ($etat['derniere_complete'] && strtotime($etat['derniere_complete']) > $ref) $ref = strtotime($etat['derniere_complete']);
            $retard = $now - ($ref + $freq + $marge * 60);
            if ($retard < 0) continue;
            $cycle = intdiv($now - $ref, $freq);
            if (ptUneFois('ronde_agent_' . $s['id'] . '_' . $ref . '_' . $cycle)) {
                ptNotifierAgent((int)$s['agent_id'], 'Ronde à faire', 'Il est temps de faire votre ronde sur ' . $s['site_nom']
                    . ($etat['restants'] ? ' (points restants : ' . implode(', ', array_slice($etat['restants'], 0, 3)) . ')' : '') . '.', 'ronde.php');
                $log[] = "ronde en retard (agent) {$s['prenom']} {$s['nom']}";
            }
            if ($retard >= 15 * 60 && ptUneFois('ronde_resp_' . $s['id'] . '_' . $ref)) {
                ptNotifierResponsables('Ronde non effectuée : ' . $s['site_nom'],
                    $s['prenom'] . ' ' . $s['nom'] . ' n\'a pas terminé de ronde depuis ' . date('H\hi', $ref)
                    . ' (fréquence : toutes les ' . (int)$s['freq'] . ' min).', 'admin/rondes.php?site=' . (int)$s['mission_id']);
                $log[] = "ronde en retard (resp) {$s['site_nom']}";
            }
        }

        // 3. Rapport hebdomadaire aux clients (lundi à partir de 7 h)
        if (date('N', $now) === '1' && (int)date('G', $now) >= 7) {
            $sem = date('o-W', $now);
            $du = date('Y-m-d', strtotime('monday last week', $now));
            $au = date('Y-m-d', strtotime('sunday last week', $now));
            $portails = $db->query("SELECT * FROM pt_portails WHERE envoi_hebdo = 1 AND revoque = 0 AND email IS NOT NULL AND email <> ''
                AND (expire_le IS NULL OR expire_le >= CURDATE())")->fetchAll();
            foreach ($portails as $p) {
                if (!ptUneFois('hebdo_' . $p['id'] . '_' . $sem)) continue;
                $lien = ptUrl('client/index.php?t=' . $p['token'] . '&v=rapport&du=' . $du . '&au=' . $au);
                $texte = "Bonjour,\n\nVeuillez trouver ci-joint le rapport de prestation du " . date('d/m', strtotime($du)) . ' au ' . date('d/m/Y', strtotime($au))
                    . ".\n\nVous pouvez aussi le consulter et le signer en ligne :\n$lien\n\nCordialement,\n" . ptMarque();
                try {
                    require_once __DIR__ . '/pdf.php';
                    $p['mission_ids'] = array_values(array_filter(array_map('intval', explode(',', $p['missions'])))) ?: [0];
                    $p['client_nom'] = null;
                    ptMailPj($p['email'], 'Rapport de la semaine — ' . ptMarque(), $texte, 'rapport_' . $du . '_' . $au . '.pdf', ptRapportClientPdf($p, $du, $au));
                } catch (Throwable $e) {
                    error_log('OV Pointage — PDF hebdo : ' . $e->getMessage());
                    ptMail($p['email'], 'Rapport de la semaine — ' . ptMarque(), $texte);
                }
                $log[] = "rapport hebdo envoyé à {$p['email']}";
            }
        }

        // 4. Purge RGPD quotidienne : positions et photos de plus d'un an
        if (ptUneFois('purge_' . date('Ymd', $now))) {
            $limite = date('Y-m-d H:i:s', $now - 365 * 86400);
            $db->prepare("UPDATE pt_services SET debut_lat = NULL, debut_lng = NULL, fin_lat = NULL, fin_lng = NULL, debut_photo = NULL, fin_photo = NULL
                WHERE debut_reel < ? AND (debut_lat IS NOT NULL OR fin_lat IS NOT NULL OR debut_photo IS NOT NULL OR fin_photo IS NOT NULL)")->execute([$limite]);
            $db->prepare("UPDATE pt_evenements SET lat = NULL, lng = NULL, photos = NULL WHERE survenu_le < ? AND (lat IS NOT NULL OR photos IS NOT NULL)")->execute([$limite]);
            $base = ptUploadsDir();
            foreach (glob($base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $dir) {
                $ym = substr($dir, -7);   // AAAA/MM
                if (strtotime(str_replace('/', '-', $ym) . '-01 +13 months') < $now) {
                    array_map('unlink', glob($dir . '/*') ?: []);
                    @rmdir($dir);
                }
            }
            $db->exec("DELETE FROM pt_notifs WHERE created_at < DATE_SUB(NOW(), INTERVAL 45 DAY)");
            $log[] = 'purge RGPD';
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('ovpt_taches')");
    }
    return $log;
}

/** Secours sans cron : au plus une exécution toutes les 5 min, après l'envoi de la page. */
function ptTachesSiBesoin(): void {
    if (PHP_SAPI === 'cli') return;
    register_shutdown_function(function () {
        try {
            if (time() - (int)getParam('pointage_taches_ts', '0') < 300) return;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            ptExecuterTaches();
        } catch (Throwable $e) {
            error_log('OV Pointage — tâches : ' . $e->getMessage());
        }
    });
}
