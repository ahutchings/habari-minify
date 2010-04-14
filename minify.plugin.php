<?php

class HabariMinify extends Plugin
{
    public function info()
    {
        return array(
            'url' => 'http://github.com/ahutchings/habari-minify',
            'name' => 'Minify',
            'description' => 'Combines, minifies, and caches JavaScript and CSS
                on demand to speed up page loads.',
            'license' => 'Apache License 2.0',
            'author' => 'Andrew Hutchings',
            'authorurl' => 'http://andrewhutchings.com',
            'version' => '0.0.9'
        );
    }

    public function action_update_check()
    {
        Update::add('Minify', 'F269AB4C-301E-11DE-9B02-636E56D89593', $this->info->version);
    }

    public function filter_plugin_config( $actions, $plugin_id )
    {
        if ($plugin_id == $this->plugin_id()) {
            $actions[] = _t('Configure');
            $actions[] = _t('Clear Cache');
        }

        return $actions;
    }

    public function action_plugin_ui( $plugin_id, $action )
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

                    printf('<p>%s</p>', $message);
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

        if ($count === 0) {
            return TRUE;
        }

        foreach ($files as $file) {
            @unlink($file);
        }

        return $count !== count(glob($pattern));
    }

    public function filter_stack_out( $stack, $stack_name, $filter )
    {
        if (count($stack) === 0) {
            return $stack;
        }

        switch ($stack_name) {
            case 'admin_stylesheet':
            case 'template_stylesheet':
                $by_type   = array();
                $internals = array();

                // group by media type
                foreach ($stack as $key => $item) {
                    if (self::is_url($item[0])) {
                        $by_type[$item[1]][$key] = $item[0];
                    } else {
                        // internal stylesheets can not be minified and can be
                        // output after all other stylesheets
                        $internals[$key] = $item;
                    }
                }

                // filtered stack
                $minified = array();

                // stylesheets to be minified together
                $files = array();

                foreach (array_keys($by_type) as $type) {
                    foreach ($by_type[$type] as $name => $file) {
                        if (self::can_minify($file)) {
                            $files[] = str_replace(Site::get_url('habari'), '', $file);
                        } else {
                            // flush anything in the files array to the filtered stack
                            if (count($files)) {
                                $minified["minified-before-$name"] = array(
                                    Site::get_url('habari') . '/m/?f=' . implode(',', $files),
                                    $type
                                );
                                $files = array();
                            }

                            // add the current element to the filtered stack
                            $minified[$name] = array($file, $type);
                        }
                    }

                    // flush anything in the files array to the filtered stack
                    if (count($files)) {
                        $minified["minified-$type-last"] = array(
                            Site::get_url('habari') . '/m/?f=' . implode(',', $files),
                            $type
                        );
                    }
                }

                // merge internal styles with the filtered stack
                $minified = array_merge($minified, $internals);

                return $minified;
            case 'admin_header_javascript':
            case 'admin_footer_javascript':
            case 'template_header_javascript':
            case 'template_footer_javascript':
                $minified = array();
                $files = array();

                foreach ($stack as $key => $element) {
                    if (self::can_minify($element)) {
                        $files[] = str_replace(Site::get_url('habari'), '', $element);
                    } else {
                        if (count($files)) {
                            $minified["minified-before-$key"] = Site::get_url('habari') . '/m/?f=' . implode(',', $files);
                            $files = array();
                        }

                        $minified[$key] = $element;
                    }
                }

                if (count($files)) {
                    $minified["minified-last"] = Site::get_url('habari') . '/m/?f=' . implode(',', $files);
                }

                return $minified;
            default:
                return $stack;
        }
    }

    /**
     * Returns true if the element is a valid, locally-hosted URL.
     *
     * @param string $element Stack element
     * @return bool
     */
    public static function can_minify( $element )
    {
        return self::is_url($element)
            && strpos($element, Site::get_url('habari')) === 0;
    }

    /**
     * Returns true if the element is a valid URL.
     *
     * @param string $element Stack element
     * @return bool
     */
    public static function is_url( $element )
    {
        return filter_var($element, FILTER_VALIDATE_URL) !== FALSE;
    }

    public function action_plugin_act_do_minify( $handler )
    {
        if (!isset($_GET['f'])) {
            header('Location: ' . Site::get_url('habari'));
            exit();
        }

        $_SERVER['DOCUMENT_ROOT'] = HABARI_PATH;

        define('MINIFY_MIN_DIR', dirname(__FILE__) . '/vendor');
        ini_set('zlib.output_compression', '0');
        set_include_path(dirname(__FILE__) . '/vendor' . PATH_SEPARATOR . get_include_path());

        $opts['bubbleCssImports'] = FALSE;
        $opts['maxAge'] = (preg_match('/&\\d/', $_SERVER['QUERY_STRING'])) ? 31536000 : Options::get('minify__max_age'); // check for URI versioning
        $opts['minApp']['groupsOnly'] = FALSE;
        $opts['minApp']['maxFiles'] = 20;
        $opts['encodeOutput'] = Options::get('minify__encode_output');

        require 'Minify.php';

        Minify::$uploaderHoursBehind = 0;
        Minify::setCache(HABARI_PATH . '/user/cache/', TRUE);
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
            'encode_output' => FALSE
        );

        foreach ($options as $option => $value) {
            if (Options::get("minify__$option") == NULL) {
                Options::set("minify__$option", $value);
            }
        }
    }
}

?>
