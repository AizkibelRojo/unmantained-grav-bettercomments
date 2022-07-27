<?php

namespace Grav\Plugin;

use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\User\User;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;
use Grav\Common\File\CompiledYamlFile;

class BetterCommentsPlugin extends Plugin
{
    protected $route = 'bettercomments';
    ///protected $enable = false;
    protected $enable = true;
    protected $comments_cache_id;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize form if the page has one. Also catches form processing if user posts the form.
     *
     * Used by Form plugin < 2.0, kept for backwards compatibility
     *
     * @deprecated
     */
    public function onPageInitialized()
    {
        /** @var Page $page */
        $page = $this->grav['page'];
        if (!$page) {
            return;
        }

        if ($this->enable) {
            $header = $page->header();
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.bettercomments.form');
                $page->header($header);
            }
        }

        $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        if ($is_ajax) {
            $action = filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW);
            switch ($action) {
                case 'save-comment':
                case 'user-comment':
                    $result = $this->commentSave(true);
                    echo json_encode([
                        'status' => $result[0],
                        'errors' => $result[1],
                        'data' => $result[2],
                        'texts' => $result[3]
                    ]);
                    break;
                case 'approve-comment':
                    $result = $this->commentApprove(true);
                    echo json_encode([
                        'status' => $result[0],
                        'message' => $result[1],
                        'data' => $result[2],
                        'texts' => $result[3]
                    ]);
                    break;
                case 'decline-comment':
                    $result = $this->commentDecline(true);
                    echo json_encode([
                        'status' => $result[0],
                        'message' => $result[1],
                        'data' => $result[2],
                        'texts' => $result[3]
                    ]);
                    break;
                case 'delete-comment':
                    $result = $this->commentDelete(true);
                    echo json_encode([
                        'status' => $result[0],
                        'message' => $result[1],
                        'data' => $result[2],
                        'texts' => $result[3]
                    ]);
                    break;
                case 'recaptcha-error':
                    $result = $this->errorRecaptcha(true);
                    echo json_encode([
                        'status' => $result[0],
                        'errors' => $result[1],
                        'data' => $result[2],
                        'texts' => $result[3]
                    ]);
                    break;
            }
            exit();
        }
    }

    private function errorRecaptcha()
    {
        $language = $this->grav['language'];
        $lang = $this->grav['language']->getLanguage();

        if (!$_SERVER["REQUEST_METHOD"] == "POST") {
            // Not a POST request, set a 403 (forbidden) response code.
            http_response_code(403);
            return [false, $language->translate('PLUGIN_COMMENTS.ERROR_SUBMISSION'), [0, 0], ''];
        }

        $message = [$language->translate('PLUGIN_COMMENTS.ERROR_RECAPTACH')];

        return [false, $message, '', ''];
    }

    private function commentApprove()
    {
        $language = $this->grav['language'];
        $lang = $this->grav['language']->getLanguage();

        if (!$this->grav['user']->authorize('admin.super')) {
            http_response_code(403);
            return [false, $language->translate('PLUGIN_COMMENTS.ADMIN_FORBIDDEN'), [0, 0]];
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        $yaml = filter_input(INPUT_POST, 'yaml', FILTER_UNSAFE_RAW);

        if (is_null($id) || is_null($yaml)) {
            http_response_code(400);
            return [false, $language->translate('PLUGIN_COMMENTS.COMMENT_ADMIN_ACTION_ERROR'), [0, 0]];
        }

        $localfile = CompiledYamlFile::instance($yaml);

        if (file_exists($yaml)) {
            $data = $localfile->content();
            if (isset($data['comments']) && is_array($data['comments'])) {
                foreach ($data['comments'] as $key => $comment) {
                    if ($comment['date'] == $id) {
                        $modify_key = $key;
                    }
                }
            }

            $data['comments'][$modify_key]['approved'] = 1;

            $texts = [$language->translate('PLUGIN_COMMENTS.VISIBLE'), $language->translate('PLUGIN_COMMENTS.DECLINE'), $language->translate('PLUGIN_COMMENTS.DELETE'), $language->translate('PLUGIN_COMMENTS.MESSAGE_LABEL'), $language->translate('PLUGIN_COMMENTS.ANSWER')];

            if ($data['comments'][$modify_key]['answer'] !== 0) {
                $texts = [$language->translate('PLUGIN_COMMENTS.VISIBLE'), $language->translate('PLUGIN_COMMENTS.DECLINE'), $language->translate('PLUGIN_COMMENTS.DELETE'), $language->translate('PLUGIN_COMMENTS.STATUS_ANSWER'), false];
            }

            $localfile->save($data);

            $entry_approved = true;
            $message = $language->translate('PLUGIN_COMMENTS.COMMENT_APPROVED');
        } else {
            $entry_approved = false;
            $message = $language->translate('PLUGIN_COMMENTS.COMMENT_ADMIN_ACTION_ERROR');
        }

        $this->commentsCacheClear($yaml);

        return [$entry_approved, $message, $id, $texts];
    }

    private function commentDecline()
    {
        $language = $this->grav['language'];
        $lang = $this->grav['language']->getLanguage();

        if (!$this->grav['user']->authorize('admin.super')) {
            http_response_code(403);
            return [false, $language->translate('PLUGIN_COMMENTS.ADMIN_FORBIDDEN'), [0, 0]];
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        $yaml = filter_input(INPUT_POST, 'yaml', FILTER_UNSAFE_RAW);

        if (is_null($id) || is_null($yaml)) {
            http_response_code(400);
            return [false, $language->translate('PLUGIN_COMMENTS.COMMENT_ADMIN_ACTION_ERROR'), [0, 0]];
        }

        $localfile = CompiledYamlFile::instance($yaml);

        if (file_exists($yaml)) {
            $data = $localfile->content();
            if (isset($data['comments']) && is_array($data['comments'])) {
                foreach ($data['comments'] as $key => $comment) {
                    if ($comment['date'] == $id) {
                        $modify_key = $key;
                    }
                }
            }

            $data['comments'][$modify_key]['approved'] = 0;

            $texts = [$language->translate('PLUGIN_COMMENTS.NOT_VISIBLE'), $language->translate('PLUGIN_COMMENTS.APPROVE'), $language->translate('PLUGIN_COMMENTS.DELETE'), $language->translate('PLUGIN_COMMENTS.MESSAGE_LABEL')];

            if ($data['comments'][$modify_key]['answer'] !== 0) {
                $texts = [$language->translate('PLUGIN_COMMENTS.NOT_VISIBLE'), $language->translate('PLUGIN_COMMENTS.APPROVE'), $language->translate('PLUGIN_COMMENTS.DELETE'), $language->translate('PLUGIN_COMMENTS.STATUS_ANSWER')];
            }

            $localfile->save($data);

            $entry_declined = true;
            $message = $language->translate('PLUGIN_COMMENTS.COMMENT_REJECTED');
        } else {
            $entry_declined = false;
            $message = $language->translate('PLUGIN_COMMENTS.COMMENT_ADMIN_ACTION_ERROR');
        }

        //clear cache
        $this->commentsCacheClear($yaml);

        return [$entry_declined, $message, $id, $texts];
    }

    private function commentDelete()
    {
        $language = $this->grav['language'];
        $lang = $this->grav['language']->getLanguage();

        if (!$this->grav['user']->authorize('admin.super')) {
            http_response_code(403);
            return [false, $language->translate('PLUGIN_COMMENTS.ADMIN_FORBIDDEN'), [0, 0]];
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        $yaml = filter_input(INPUT_POST, 'yaml', FILTER_UNSAFE_RAW);

        if (is_null($id) || is_null($yaml)) {
            http_response_code(400);
            return [false, $language->translate('PLUGIN_COMMENTS.COMMENT_ADMIN_ACTION_ERROR'), [0, 0]];
        }

        $localfile = CompiledYamlFile::instance($yaml);

        if (file_exists($yaml)) {
            $data = $localfile->content();
            if (isset($data['comments']) && is_array($data['comments'])) {
                foreach ($data['comments'] as $key => $comment) {
                    if ($comment['date'] == $id) {
                        $modify_key = $key;
                    }
                }
            }

            //unset($data['comments'][$modify_key]) //real delete
            $data['comments'][$modify_key]['approved'] = 2;

            $localfile->save($data);

            $entry_removed = true;
            $message = $language->translate('PLUGIN_COMMENTS.DELETED_MSG');
        } else {
            $entry_removed = false;
            $message = $language->translate('PLUGIN_COMMENTS.COMMENT_ADMIN_ACTION_ERROR');
        }

        //clear cache
        $this->commentsCacheClear($yaml);

        return [$entry_removed, $message, $id, ''];
    }

    private function commentSave()
    {

        $language = $this->grav['language'];
        $user = $this->grav['user']->authenticated ? $this->grav['user']->username : '';

        if (!$_SERVER["REQUEST_METHOD"] == "POST") {
            // Not a POST request, set a 403 (forbidden) response code.
            http_response_code(403);
            return [false, $language->translate('PLUGIN_COMMENTS.ERROR_SUBMISSION'), [0, 0], ''];
        }

        if (!isset($_POST['data']) || !is_array($_POST['data'])) {
            // Set a 400 (bad request) response code and exit.
            http_response_code(400);
            return [false, $language->translate('PLUGIN_COMMENTS.ERROR_MISSING_DATA'), [0, 0], ''];
        }

        $input = array();
        $input['name'] = isset($_POST['data']['name']) ? filter_var($_POST['data']['name'], FILTER_UNSAFE_RAW) : null;
        $input['email'] = isset($_POST['data']['email']) ? filter_var($_POST['data']['email'], FILTER_UNSAFE_RAW) : null;
        $input['text'] = isset($_POST['data']['text']) ? filter_var($_POST['data']['text'], FILTER_UNSAFE_RAW) : null;
        $input['title'] = isset($_POST['data']['title']) ? filter_var($_POST['data']['title'], FILTER_UNSAFE_RAW) : null;
        $input['lang'] = isset($_POST['data']['lang']) ? filter_var($_POST['data']['lang'], FILTER_UNSAFE_RAW) : null;
        $input['path'] = isset($_POST['data']['path']) ? filter_var($_POST['data']['path'], FILTER_UNSAFE_RAW) : null;
        $input['answer'] = isset($_POST['data']['answer']) ? filter_var($_POST['data']['answer'], FILTER_UNSAFE_RAW) : null;
        $input['form-name'] = filter_input(INPUT_POST, '__form-name__', FILTER_UNSAFE_RAW);
        $input['form-nonce'] = filter_input(INPUT_POST, 'form-nonce', FILTER_UNSAFE_RAW);

        // if (!Utils::verifyNonce($input['form-nonce'], 'comments')) {
        // 	http_response_code(403);
        //     return [false, 'Invalid security nonce', [0, $input['form-nonce']]];
        // }

        if (is_null($input['title']) || is_null($input['text'])) {
            // Set a 400 (bad request) response code and exit.
            http_response_code(400);
            return [false, $language->translate('PLUGIN_COMMENTS.ERROR_MISSING_DATA'), [0, 0], ''];
        }

        if (isset($this->grav['user'])) {
            $user = $this->grav['user'];
            if ($user->authenticated) {
                $input['name'] = $user->fullname;
                $input['email'] = $user->email;
            }
        }

        $input['name'] = trim($input['name']);
        $input['email'] = trim($input['email']);
        $input['text'] = trim($input['text']);
        $form_errors = [];

        if (empty($input['name'])) {
            array_push($form_errors, $language->translate('PLUGIN_COMMENTS.ERROR_NAME'));
        }

        if (empty($input['email'])) {
            array_push($form_errors, $language->translate('PLUGIN_COMMENTS.ERROR_MAIL'));
        }

        if (empty($input['text'])) {
            array_push($form_errors, $language->translate('PLUGIN_COMMENTS.ERROR_TEXT'));
        }

        if (count($form_errors) > 0) {
            return [false, $form_errors, '', ''];
        }
        $time = time();

        $this->addComment($input['name'], $input['email'], $input['text'], $input['answer'], $input['title'], $input['lang'], $time, $input['path']);

        $comment_data = [];
        $texts = [];

        if ($this->grav['user']->authorize('admin.super') && $this->grav['uri']->path() === '/admin') {
            //Admin return
            $comment_data = [$input['name'], $input['email'], $input['text'], $input['title'], $time];
            $texts = [$language->translate('PLUGIN_COMMENTS.VISIBLE'), $language->translate('PLUGIN_COMMENTS.STATUS_ANSWER'), $language->translate('PLUGIN_COMMENTS.DECLINE'), $language->translate('PLUGIN_COMMENTS.DELETE'), $language->translate('PLUGIN_COMMENTS.PAGE'), $language->translate('PLUGIN_COMMENTS.DATE')];
        }

        return [true, '', $comment_data, $texts];
    }

    public function addComment($name, $email, $text, $answer, $title, $lang, $time, $path)
    {
        if (!$this->active) {
            return;
        }

        /** @var Language $language */
        $language = $this->grav['language'];
        $lang = $language->getLanguage();

        $filename = DATA_DIR . 'comments';
        $filename .= ($lang ? '/' . $lang : '');
        $filename .= $path . '.yaml';
        $file = File::instance($filename);

        if ($this->grav['user']->authorize('admin.super')) {
            $approved = 1;
        } else {
            $approved = 0;
        }

        if (file_exists($filename)) {
            $data = Yaml::parse($file->content());

            $data['comments'][] = [
                'text' => $text,
                'date' => $time,
                'author' => $name,
                'email' => $email,
                'approved' => $approved,
                'answer' => (int)$answer
            ];
        } else {
            $data = array(
                'title' => $title,
                'lang' => $lang,
                'comments' => array([
                    'text' => $text,
                    'date' => $time,
                    'author' => $name,
                    'email' => $email,
                    'approved' => $approved,
                    'answer' => (int)$answer
                ])
            );
        }

        $file->save(Yaml::dump($data));

        //clear cache
        $this->commentsCacheClear($filename);
    }

    protected function commentsCacheClear($filename)
    {
        if ($this->comments_cache_id !== NULL) {
            $this->grav['cache']->delete($this->comments_cache_id);
        } else {
            $cache = $this->grav['cache'];
            $filename = array_reverse(explode('/', str_replace('.yaml', '', $filename)));
            $uri = '/' . $filename[1] . '/' . $filename[0];
            $comments_cache_id = md5('comments-data' . $cache->getKey() . '-' . $uri);

            $this->grav['cache']->delete($comments_cache_id);
        }
    }

    /**
     * Add the comment form information to the page header dynamically
     *
     * Used by Form plugin >= 2.0
     */
    public function onFormPageHeaderProcessed(Event $event)
    {
        $header = $event['header'];

        if ($this->enable) {
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.bettercomments.form');
            }
        }

        $event->header = $header;
    }

    public function onTwigSiteVariables()
    {
        // Old way
        $enabled = $this->enable;
        $all_comments = $this->fetchComments();

        $comments = $all_comments[0];
        if (count($all_comments[1]) > 0) {
            $comments_answered = $all_comments[1];
        } else {
            $comments_answered = [];
        }


        $this->grav['twig']->enable_comments_plugin = $enabled;
        $this->grav['twig']->comments = $comments;
        $this->grav['twig']->answers = $comments_answered;

        // New way
        $this->grav['twig']->twig_vars['enable_comments_plugin'] = $enabled;
        $this->grav['twig']->twig_vars['comments'] = $comments;
        $this->grav['twig']->twig_vars['answers'] = $comments_answered;

        $this->grav['assets']
            ->addCss('plugin://bettercomments/assets/bettercomments.css');
        $this->grav['assets']
            ->addJs('plugin://bettercomments/assets/bettercomments.js');
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable()
    {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.bettercomments.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.bettercomments.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach ($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Frontend side initialization
     */
    public function initializeFrontend()
    {
        $this->calculateEnable();

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->enable) {
            $this->enable([
                'onFormProcessed' => ['onFormProcessed', 0],
                'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
                'onPageInitialized' => ['onPageInitialized', 10],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }

        $cache = $this->grav['cache'];
        $uri = $this->grav['uri'];

        //init cache id
        $this->comments_cache_id = md5('comments-data' . $cache->getKey() . '-' . $uri->url());
    }

    /**
     * Admin side initialization
     */
    public function initializeAdmin()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
        ]);

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);

        $page = $this->grav['uri']->param('page');
        $comments = $this->getLastComments($page);

        if ($page > 0) {
            echo json_encode($comments);
            exit();
        }

        $this->grav['twig']->comments = $comments;
        $this->grav['twig']->pages = $this->fetchPages();
    }

    /**
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->initializeAdmin();
        } else {
            $this->initializeFrontend();
        }
    }

    private function getFilesOrderedByModifiedDate($path = '')
    {
        $files = [];

        if (!$path) {
            $path = DATA_DIR . 'comments';
        }

        if (!file_exists($path)) {
            Folder::mkdir($path);
        }

        $dirItr = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filterItr = new RecursiveFolderFilterIterator($dirItr);
        $itr = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);

        $itrItr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
        $filesItr = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

        // Collect files if modified in the last 7 days
        foreach ($filesItr as $filepath => $file) {
            $modifiedDate = $file->getMTime();
            $sevenDaysAgo = time() - (7 * 24 * 60 * 60);

            if ($modifiedDate < $sevenDaysAgo) {
                continue;
            }

            $files[] = (object)array(
                "modifiedDate" => $modifiedDate,
                "fileName" => $file->getFilename(),
                "filePath" => $filepath,
                "data" => Yaml::parse(file_get_contents($filepath))
            );
        }

        // Traverse folders and recurse
        foreach ($itr as $file) {
            if ($file->isDir()) {
                $this->getFilesOrderedByModifiedDate($file->getPath() . '/' . $file->getFilename());
            }
        }

        // Order files by last modified date
        usort($files, function ($a, $b) {
            return !($a->modifiedDate > $b->modifiedDate);
        });

        return $files;
    }

    private function getLastComments($page = 0)
    {
        $number = 30;
        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();
        $comments = [];
        $language = $this->grav['language'];
        $lang = $this->grav['language']->getLanguage();

        foreach ($files as $file) {
            $data = Yaml::parse(file_get_contents($file->filePath));

            for ($i = 0; $i < count($data['comments']); $i++) {

                $data['comments'][$i]['pageTitle'] = $data['title'];
                $data['comments'][$i]['fileName'] = $file->fileName;
                $data['comments'][$i]['filePath'] = $file->filePath;
                $data['comments'][$i]['timestamp'] = $data['comments'][$i]['date'];
            }
            //Filter "Deleted" comments
            $data['comments'] = array_filter($data['comments'], function ($k) {
                return $k['approved'] == '1' || $k['approved'] == '0';
            });
            if (count($data['comments'])) {
                $comments = array_merge($comments, $data['comments']);
            }
        }

        //Order comments by date
        usort($comments, function ($a, $b) {
            return !($a['timestamp'] > $b['timestamp']);
        });

        $totalAvailable = count($comments);
        $comments = array_slice($comments, $page * $number, $number);
        $totalRetrieved = count($comments);

        return (object)array(
            "comments" => $comments,
            "page" => $page,
            "totalAvailable" => $totalAvailable,
            "totalRetrieved" => $totalRetrieved,
            "textsForComposition" => [$language->translate('PLUGIN_COMMENTS.VISIBLE'), $language->translate('PLUGIN_COMMENTS.NOT_VISIBLE'), $language->translate('PLUGIN_COMMENTS.APPROVE'), $language->translate('PLUGIN_COMMENTS.DELETE'), $language->translate('PLUGIN_COMMENTS.DECLINE'), $language->translate('PLUGIN_COMMENTS.ANSWER'), $language->translate('PLUGIN_COMMENTS.PAGE'), $language->translate('PLUGIN_COMMENTS.DATE')]
        );
    }

    /**
     * Return the comments associated to the current route
     */
    private function fetchComments()
    {
        $cache = $this->grav['cache'];
        //search in cache
        if ($comments = $cache->fetch($this->comments_cache_id)) {
            return $comments;
        }

        $lang = $this->grav['language']->getLanguage();
        $filename = $lang ? '/' . $lang : '';
        $filename .= $this->grav['uri']->path() . '.yaml';

        $data = $this->getDataFromFilename($filename);
        $all_comments = isset($data['comments']) ? $data['comments'] : null;
        $comments = [];
        $comments_answer = array();

        if ($all_comments !== null) {
            foreach ($all_comments as $comment) {
                // if ((int)$comment['approved'] !== 2) {
                //     array_push($comments, $comment);
                // }
                if ((int)$comment['approved'] !== 2 && (int)$comment['answer'] === 0) {
                    array_push($comments, $comment);
                }
                if ((int)$comment['approved'] !== 2 && (int)$comment['answer'] !== 0) {
                    array_push($comments_answer, $comment);
                }
            }
        }

        //save to cache if enabled
        $cache->save($this->comments_cache_id, [$comments, $comments_answer]);
        return [$comments, $comments_answer];
    }

    /**
     * Return the latest commented pages
     */
    private function fetchPages()
    {
        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();
        $pages = [];

        foreach ($files as $file) {
            $file_comments = [];
            foreach ($file->data['comments'] as $comment) {
                if ((int)$comment['approved'] !== 2) {
                    array_push($file_comments, $comment);
                }
            }
            $pages[] = [
                'title' => $file->data['title'],
                'commentsCount' => count($file_comments),
                'lastCommentDate' => date('D, d M Y H:i:s', $file->modifiedDate)
            ];
        }

        return $pages;
    }


    /**
     * Given a data file route, return the YAML content already parsed
     */
    private function getDataFromFilename($fileRoute)
    {
        //Single item details
        $fileInstance = File::instance(DATA_DIR . 'comments/' . $fileRoute);

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        return Yaml::parse($fileInstance->content());
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Add plugin templates path
     */
    public function onTwigAdminTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_COMMENTS.COMMENTS'] = ['route' => $this->route, 'icon' => 'fa-comment'];
    }

    /**
     * Exclude comments from the Data Manager plugin
     */
    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'bettercomments';
    }
}
