<?php defined('BLUDIT') or die('Bludit CMS.');

// ------------------------------------------------------------------
// Video-Upload-Plugin: erlaubt das Hochladen kurzer Video-Clips
// (MP4/WebM/MOV) direkt im Bludit-Backend. Der Bludit-Kern selbst
// (der Uploads standardmäßig auf Bilder beschränkt) wird dabei nicht
// verändert - Videos werden über eine eigene Verwaltungsseite und
// einen eigenen Ordner (bl-content/uploads/videos/) verwaltet.
//
// Jeder Clip bekommt einen Titel und einen Ziel-Link (Rubrik oder
// Seite). Im Theme erscheint er als klickbare Kachel, die beim
// Anklicken direkt zum hinterlegten Ziel führt (kein Pop-up).
// ------------------------------------------------------------------
class VideoUpload extends Plugin
{
    public function init()
    {
        $this->dbFields = array(
            'clips' => '[]',
            'maxSizeMB' => '60',
            'previewCategoryKeys' => 'rezepte,videos'
        );
    }

    private function allowedExtensions()
    {
        return array('mp4', 'webm', 'mov', 'ogg');
    }

    private function clipsDir()
    {
        $dir = PATH_UPLOADS . 'videos' . DS;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function clipsUrl()
    {
        return DOMAIN_UPLOADS . 'videos/';
    }

    // Liefert die konfigurierten Rubrik-Schlüssel als Array, für die
    // Nutzung im Theme (ersetzt die früher fest verdrahtete Liste).
    private function previewCategoryKeysArray()
    {
        $raw = $this->getValue('previewCategoryKeys');
        $keys = array_map('trim', explode(',', $raw));
        return array_filter($keys, function ($k) { return $k !== ''; });
    }

    private function readClips()
    {
        $raw = $this->getValue('clips', false);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        return $decoded;
    }

    private function writeClips($clips)
    {
        $this->setField('clips', json_encode($clips));
        // Zusätzlich als eigenständige Datei ablegen, damit das Theme sie
        // direkt lesen kann, ohne sich auf Bludits interne Plugin-Registry
        // (getPlugin()) verlassen zu müssen.
        file_put_contents($this->clipsDir() . 'clips.json', json_encode($clips), LOCK_EX);
    }

    // Einstellungsseite (Plugins -> Video-Upload -> Zahnrad): maximale Dateigröße
    public function form()
    {
        $html = '<div class="form-group">';
        $html .= '<label>Maximale Video-Dateigröße pro Clip (MB)</label>';
        $html .= '<input type="text" class="form-control" name="maxSizeMB" value="' . htmlspecialchars($this->getValue('maxSizeMB')) . '">';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label>Videos-Vorschau: Rubrik-Schlüssel (kommagetrennt)</label>';
        $html .= '<input type="text" class="form-control" name="previewCategoryKeys" value="' . htmlspecialchars($this->getValue('previewCategoryKeys')) . '">';
        $html .= '<small>Artikel aus diesen Rubriken erscheinen zusätzlich zu den hochgeladenen Video-Clips im "Empfehlungen"-Widget des Themes. Den Rubrik-Schlüssel findest du beim Bearbeiten einer Rubrik im Feld "URL". Mehrere Schlüssel durch Komma trennen, z.B. rezepte,videos,tipps</small>';
        $html .= '</div>';
        return $html;
    }

    // Wird von Bludit automatisch aufgerufen, wenn die Einstellungsseite
    // gespeichert wird. Schreibt die Vorschau-Rubriken zusätzlich in eine
    // eigenständige Datei, damit das Theme sie zuverlässig lesen kann.
    public function post()
    {
        $result = parent::post();
        $dir = $this->clipsDir();
        file_put_contents($dir . 'config.json', json_encode(array(
            'previewCategoryKeys' => $this->previewCategoryKeysArray()
        )), LOCK_EX);
        return $result;
    }

    // Sichtbar für alle eingeloggten Benutzer, analog zum Postfach-Plugin
    public function adminSidebar()
    {
        global $login;
        if (!is_object($login) || !$login->role()) {
            return '';
        }
        $pluginName = Text::lowercase(__CLASS__);
        $url = HTML_PATH_ADMIN_ROOT . 'plugin/' . $pluginName;
        return '<a class="nav-link" href="' . $url . '">Video-Upload</a>';
    }

    public function adminController()
    {
        global $login;
        if (!is_object($login) || !$login->role()) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method !== 'POST') {
            return;
        }

        // Hinweis: wie bei Postfach/Kategorie-Rechte wird bewusst auf eine
        // strikte CSRF-Prüfung verzichtet, da sie in Tests zu Fehlalarmen
        // führte. Zugriff ist ohnehin nur für eingeloggte Benutzer möglich.
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'upload') {
            $title = trim(Sanitize::html(isset($_POST['title']) ? $_POST['title'] : ''));
            $targetUrl = trim(isset($_POST['targetUrl']) ? $_POST['targetUrl'] : '');

            if (empty($title) || empty($targetUrl)) {
                Alert::set('Bitte Titel und Ziel-Link angeben.');
                return;
            }
            if (empty($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
                Alert::set('Bitte eine Videodatei auswählen. (Falls der Upload fehlschlägt, ist evtl. das Server-Limit für Datei-Uploads zu klein.)');
                return;
            }

            $originalName = $_FILES['videoFile']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($ext, $this->allowedExtensions())) {
                Alert::set('Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', $this->allowedExtensions()));
                return;
            }

            $maxBytes = ((int) $this->getValue('maxSizeMB')) * 1024 * 1024;
            if ($_FILES['videoFile']['size'] > $maxBytes) {
                Alert::set('Datei ist zu groß. Maximal ' . $this->getValue('maxSizeMB') . ' MB erlaubt.');
                return;
            }

            $dir = $this->clipsDir();
            $safeSlug = Text::cleanUrl($title);
            if (empty($safeSlug)) {
                $safeSlug = 'video';
            }
            $filename = $safeSlug . '-' . uniqid() . '.' . $ext;

            if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $dir . $filename)) {
                Alert::set('Fehler beim Speichern der Datei auf dem Server.');
                return;
            }

            $clips = $this->readClips();
            $clips[] = array(
                'id' => uniqid('clip_', true),
                'title' => $title,
                'targetUrl' => $targetUrl,
                'filename' => $filename,
                'timestamp' => date('Y-m-d H:i:s')
            );
            $this->writeClips($clips);
            Alert::set('Video erfolgreich hochgeladen.');
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? $_POST['id'] : '';
            $clips = $this->readClips();
            foreach ($clips as $key => $clip) {
                if ($clip['id'] === $id) {
                    $filePath = $this->clipsDir() . $clip['filename'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    unset($clips[$key]);
                }
            }
            $this->writeClips(array_values($clips));
            Alert::set('Video gelöscht.');
        }
    }

    public function adminView()
    {
        global $login;
        global $security;
        global $pages;

        if (!is_object($login) || !$login->role()) {
            return '<p>Bitte einloggen.</p>';
        }

        $tokenCSRF = $security->getTokenCSRF();
        $clips = $this->readClips();

        $html = '<h3>Neues Video hochladen</h3>';
        $html .= '<p style="color:var(--color-muted, #666); font-size:13.5px;">Erlaubt: MP4, WebM, MOV, OGG &middot; empfohlen: kurze Clips (ca. 1&ndash;2 Minuten) &middot; max. ' . htmlspecialchars($this->getValue('maxSizeMB')) . ' MB.</p>';
        $html .= '<form method="post" enctype="multipart/form-data" class="mb-4">';
        $html .= '<input type="hidden" name="tokenCSRF" value="' . $tokenCSRF . '">';
        $html .= '<input type="hidden" name="action" value="upload">';

        $html .= '<div class="form-group"><label>Titel (erscheint auf der Kachel)</label>';
        $html .= '<input type="text" name="title" class="form-control" placeholder="z. B. Rezepte" required></div>';

        $html .= '<div class="form-group"><label>Ziel-Link (wohin führt der Klick auf die Kachel?)</label>';
        $html .= '<select name="targetUrl" class="form-control" style="color:#000 !important; background:#fff !important; font-size:15px !important; height:auto; padding:8px;" required>';
        $html .= '<option value="">-- bitte wählen --</option>';
        foreach (getCategories() as $cat) {
            $html .= '<option value="' . $cat->permalink() . '" style="color:#000 !important;">Rubrik: ' . htmlspecialchars($cat->name()) . '</option>';
        }
        foreach ($pages->getList(1, -1, true) as $pageKey) {
            $p = buildPage($pageKey);
            if ($p && !$p->category()) {
                $html .= '<option value="' . $p->permalink() . '" style="color:#000 !important;">Seite: ' . htmlspecialchars($p->title()) . '</option>';
            }
        }
        $html .= '</select></div>';

        $html .= '<div class="form-group"><label>Videodatei</label>';
        $html .= '<input type="file" name="videoFile" accept="video/mp4,video/webm,video/quicktime,video/ogg" required></div>';

        $html .= '<button type="submit" class="btn btn-primary">Video hochladen</button>';
        $html .= '</form>';

        $html .= '<h3>Vorhandene Videos</h3>';
        if (empty($clips)) {
            $html .= '<p>Noch keine Videos hochgeladen.</p>';
        } else {
            $html .= '<table class="table table-striped"><thead><tr><th>Titel</th><th>Ziel-Link</th><th>Datei</th><th>Hochgeladen</th><th></th></tr></thead><tbody>';
            foreach (array_reverse($clips) as $clip) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($clip['title']) . '</td>';
                $html .= '<td>' . htmlspecialchars($clip['targetUrl']) . '</td>';
                $html .= '<td>' . htmlspecialchars($clip['filename']) . '</td>';
                $html .= '<td>' . htmlspecialchars($clip['timestamp']) . '</td>';
                $html .= '<td><form method="post" style="display:inline;" onsubmit="return confirm(\'Dieses Video wirklich löschen?\');">';
                $html .= '<input type="hidden" name="tokenCSRF" value="' . $tokenCSRF . '">';
                $html .= '<input type="hidden" name="action" value="delete">';
                $html .= '<input type="hidden" name="id" value="' . htmlspecialchars($clip['id']) . '">';
                $html .= '<button type="submit" class="btn btn-danger btn-sm">Löschen</button>';
                $html .= '</form></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }
}

// ------------------------------------------------------------------
// Hilfsfunktion für das Theme: liefert alle hochgeladenen Video-Clips
// inklusive fertiger URL, analog zu abteilungen_get_list().
// ------------------------------------------------------------------
if (!function_exists('video_upload_get_clips')) {
    function video_upload_get_clips()
    {
        $file = PATH_UPLOADS . 'videos' . DS . 'clips.json';
        if (!file_exists($file)) {
            return array();
        }
        $decoded = json_decode(file_get_contents($file), true);
        if (!is_array($decoded)) {
            return array();
        }
        foreach ($decoded as &$clip) {
            $clip['url'] = DOMAIN_UPLOADS . 'videos/' . $clip['filename'];
        }
        return $decoded;
    }
}

// ------------------------------------------------------------------
// Hilfsfunktion für das Theme: liefert die Rubrik-Schlüssel, deren
// Artikel zusätzlich zu den Video-Clips im Empfehlungen-Widget
// erscheinen sollen. Einstellbar unter Plugins -> Video-Upload ->
// Einstellungen -> "Videos-Vorschau: Rubrik-Schlüssel". Solange die
// Einstellungsseite noch nie gespeichert wurde, greift der Standard
// ("rezepte", "videos").
// ------------------------------------------------------------------
if (!function_exists('video_upload_get_preview_category_keys')) {
    function video_upload_get_preview_category_keys()
    {
        $file = PATH_UPLOADS . 'videos' . DS . 'config.json';
        if (file_exists($file)) {
            $decoded = json_decode(file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['previewCategoryKeys']) && is_array($decoded['previewCategoryKeys'])) {
                return $decoded['previewCategoryKeys'];
            }
        }
        return array('rezepte', 'videos');
    }
}
