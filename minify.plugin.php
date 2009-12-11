<?php

class HabariMinify extends Plugin
{
    public function info()
    {
        return array(
            'url' => 'http://andrewhutchings.com/projects',
            'name' => 'Minify',
            'description' => 'Combines, minifies, and caches JavaScript and CSS
                on demand to speed up page loads.',
            'license' => 'Apache License 2.0',
            'author' => 'Andrew Hutchings',
            'authorurl' => 'http://andrewhutchings.com',
            'version' => '0.0.8'
        );
    }

    public function action_update_check()
    {
        Update::add('Minify', 'F269AB4C-301E-11DE-9B02-636E56D89593', $this->info->version);
    }

    public function filter_plugin_config($actions, $plugin_id)
    {
        if ($plugin_id == $this->plugin_id()) {
            $actions[] = _t('Configure');
            $actions[] = _t('Clear Cache');
        }

        return $actions;
    }

    public function action_plugin_ui($plugin_id, $action)
    {
        if ( $plugin_id == $this->plugin_id() ) {
            switch ($action) {
                case _t('Configure'):
                    $form = new FormUI(strtolower(get_class($this)));
                    $form->append('checkbox', 'encodeoutput', 'minify__encode_output', _t('Enable content encoding (compression)'));
                    $form->append('text', 'max_age', 'minify__max_age', _t('Maximum cache age, in seconds'));

                    $form->append('submit', 'save', 'Save');
                    $form->out();
                    break;
                case _t('Clear Cache'):
                    if (self::clear_cache()) {
                        $message = _t('The Minify cache was cleared successfully.');
                    } else {
                        $message = _t('Unable to clear the Minify cache.');
                    }

                    printf("<p>%s</p>", $message);
                    break;
            }
        }
    }

    /**
     * Deletes Minify cache files.
     *
     * @return bool
     */
    private function clear_cache()
    {
        $pattern = HABARI_PATH . '/user/cache/minify_*';
        $files   = glob($pattern);
        $count   = count($files);

        if ($count == 0) {
            return true;
        }

        foreach ($files as $file) {
            @unlink($file);
        }

        return $count !== count(glob($pattern));
    }

    public function filter_stack_out($stack, $stack_name, $filter)
    {
        if (count($stack) == 0) {
            return $stack;
        }

        switch ($stack_name) {
            case 'admin_stylesheet':
            case 'template_stylesheet':
                $media = array();
                $out = array();

                foreach ($stack as $item) {
                    $media[$item[1]][] = str_replace(Site::get_url('habari'), '', $item[0]);
                }

                $types = array_keys($media);

                for ($i = 0; $i < count($media); $i++) {
                    $out[$types[$i]] = array(Site::get_url('habari') . '/m/?f=' . implode(',', $media[$types[$i]]), $types[$i]);
                }

                return $out;
            case 'admin_header_javascript':
            case 'template_header_javascript':
                $files = array();

                foreach ($stack as $file) {
                    $files[] = str_replace(Site::get_url('habari'), '', $file);
                }

                return array('minified' => Site::get_url('habari') . '/m/?f=' . implode(',', $files));
            default:
                return $stack;
        }
    }

    public function action_plugin_act_do_minify($handler)
    {
        if (!isset($_GET['f'])) {
            header('Location: ' . Site::get_url('habari'));
            exit();
        }

        $_SERVER['DOCUMENT_ROOT'] = HABARI_PATH;

        define('MINIFY_MIN_DIR', dirname(__FILE__) . '/vendor');
        ini_set('zlib.output_compression', '0');
        set_include_path(dirname(__FILE__) . '/vendor' . PATH_SEPARATOR . get_include_path());

        $opts['bubbleCssImports'] = false;
        $opts['maxAge'] = (preg_match('/&\\d/', $_SERVER['QUERY_STRING'])) ? 31536000 : Options::get('minify__max_age'); // check for URI versioning
        $opts['minApp']['groupsOnly'] = false;
        $opts['minApp']['maxFiles'] = 20;
        $opts['encodeOutput'] = Options::get('minify__encode_output');

        require 'Minify.php';

        Minify::$uploaderHoursBehind = 0;
        Minify::setCache(HABARI_PATH . '/user/cache/', true);
        Minify::serve('MinApp', $opts);
    }

    public function action_init()
    {
        $this->add_rule('"m"/', 'do_minify');
    }

    /**
     * Setup default options on activation.
     *
     * @param string $file the plugin file being activated
     */
    public function action_plugin_activation( $file )
    {
        if ($file != str_replace('\\','/', $this->get_file())) {
            return;
        }

        $options = array(
            'max_age' => 1800,
            'encode_output' => false
        );

        foreach ($options as $option => $value) {
            if (Options::get('minify__'.$option) == null) {
                Options::set('minify__'.$option, $value);
            }
        }
    }
}

?>
