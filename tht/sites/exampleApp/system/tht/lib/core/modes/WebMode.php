<?php

namespace o;

class WebMode {

    static private $ROUTE_HOME = 'home';

    static private $requestHeaders = [];
    static private $routeParams = [];
    static private $sideloadPage = '';

    static public function main($sideloadPage = false) {

        self::$sideloadPage = $sideloadPage;

        if (self::serveStaticFile()) {
            return false;
        }

        Security::validateRequestOrigin();
        Security::initResponseHeaders();

        if (Tht::getConfig('downtime')) {
            self::downtimePage(Tht::getConfig('downtime'));
        }

        $controllerFile = self::initRoute();

        if ($controllerFile) {
            self::executeController($controllerFile);
        }

        self::onEnd();

        return true;
    }

    static public function onEnd() {

        self::handleBlankOutput();
        PrintBuffer::flush();
        HitCounter::add();
        self::printPerf();
    }

    static function handleBlankOutput() {
        // Prevent blank white screen
        if (!Tht::module('Output')->sentResponseType && !PrintBuffer::hasItems()) {
            if (Tht::module('Page')->didCreatePage) {
                ErrorHandler::setStdLibHelpLink('module', 'Page', 'send');
                Tht::error('A Page object was created but no output was sent.  Try: Call `$page.send()`');
            }
            else {
                ErrorHandler::setStdLibHelpLink('module', 'Output');
                Tht::error('No output was sent to the browser.');
            }
        }
    }

    // Serve directly if requested a static file in testServer mode
    static private function serveStaticFile() {

        if (!Tht::isMode('testServer')) { return false; }

        // Dotted filename
        if (preg_match('/\.[a-z0-9]{2,}$/', $_SERVER['SCRIPT_NAME'])) {

            $path = $_SERVER['SCRIPT_NAME'];

            // Enable client-side caching
            if (isset($_GET['v'])) {
                header("Cache-Control: public, max-age=2592000, immutable");
            }

            // Send the right encoding for gz files
            if (preg_match('/\.gz/', $path)) {
                $file = $_SERVER["SCRIPT_FILENAME"];
                header("Content-Encoding: gzip");
                $mime = strpos($path, '.js') !== false ? 'application/javascript' : 'text/css';
                header("Content-Type: " . $mime);
                readfile($file);
                exit(0);
            }

            return true;
        }

        // Need to construct path manually.
        // See: https://github.com/joelesko/tht/issues/2
        $path = $_SERVER["DOCUMENT_ROOT"] . $_SERVER['SCRIPT_NAME'];

        if ($_SERVER['SCRIPT_NAME'] !== '/' && file_exists($path)) {

            // is a static file
            if (!is_dir($path)) {
                return true;
            }
        }

    }

    static private function printPerf () {

        $resType = Tht::module('Output')->sentResponseType;

        if (!Tht::module('Request')->u_is_ajax()
              && ($resType == 'html' || $resType == '')) {

            Tht::module('Perf')->printResults();
        }
    }

    // TODO: Localize
    static private function downtimePage($file) {

        http_response_code(503);
        $downPage = Tht::module('File')->u_document_path($file);

        if ($file !== true && file_exists($downPage)) {
            print(file_get_contents($downPage));
        }
        else {
            $font = Tht::module('Output')->font('sansSerif');
            echo "<div style='padding: 2rem; text-align: center; font-family: $font'><h1>Temporarily Down for Maintenance</h1><p>Sorry for the inconvenience.  We'll be back soon.</p></div>";
        }

        Tht::exitScript(0);
    }

    static private function initRoute () {

        Tht::module('Perf')->u_start('tht.route');

        $path = self::getScriptPath();

        // Always redirect '/home' to '/'
        if ($path == '/home') {
            return Tht::module('Output')->u_redirect(OTypeString::create('url', '/'), 301);
        }

        $controllerFile = self::getControllerForPath($path);

        Tht::module('Perf')->u_stop();

        return $controllerFile;
    }

    static public function runRoute($path) {

        $controllerFile = self::getControllerForPath($path);

        if ($controllerFile) {
            self::executeController($controllerFile);
        }
        else {
            Tht::error("No route found for path: `$path`");
        }
    }

    static public function runStaticRoute($url) {

        $routes = Tht::getTopConfig('routes');
        if (!isset($routes[$url])) { return false; }

        $file = self::getFullRoutePath($routes[$url]);
        Tht::executeController($file);

        Tht::exitScript(0);
    }

    static private function getFullRoutePath($relPath) {
        return Tht::path('pages', $relPath);
    }

    static private function getScriptPath() {

        if (self::$sideloadPage) {
            return self::$sideloadPage;
        }

        $path = Tht::module('Request')->u_get_url()->u_get_path();
        Security::validateRoutePath($path);

        return $path;
    }

    static private function getControllerForPath($path) {

        $routes = Tht::getTopConfig('routes');

        if (!isset($routes['/'])) {
            $routes['/'] = 'home.tht';
        }

        if (defined('BASE_URL') ) {
            $path = preg_replace('#' . BASE_URL . '#', '', $path);
            if ($path == '') { $path = '/'; }
        }

        $controllerFile = '';

        if (isset($routes[$path])) {
            // static path
            $controllerFile = self::getFullRoutePath($routes[$path]);
        }
        else {
            $controllerFile = self::getDynamicController($routes, $path);
            if ($controllerFile === false) {
                $controllerFile = self::getStaticController($path);
            }
        }

        return $controllerFile;
    }

    // path with dynamic parts e.g. '/blog/{articleId}'
    static private function getDynamicController($routes, $path) {

        $pathParts = explode('/', ltrim($path, '/'));
        $numPathParts = count($pathParts);

        $routeTargets = [];

        foreach (unv($routes) as $match => $controllerPath) {

            if (strpos($match, '{') === false) {
                continue;
            }

            $params = [];
            $matchParts = explode('/', ltrim($match, '/'));
            $numMatchParts = count($matchParts);

            $routeTargets[strtolower('/' . $controllerPath)] = true;

            if ($numMatchParts === $numPathParts) {

                $isMatch = true;
                foreach (range(0, $numMatchParts - 1) as $i) {

                    $mPart = $matchParts[$i];

                    if ($mPart[0] === '{' && $mPart[strlen($mPart)-1] === '}') {

                        // route placeholder
                        $token = substr($mPart, 1, strlen($mPart)-2);

                        if (preg_match('/[^a-zA-Z0-9]/', $token)) {

                            Tht::configError("Route placeholder `{$token}` should only"
                                . " contain letters and numbers (no spaces).");
                        }

                        $params[$token] = $pathParts[$i];
                    }
                    else {

                        if ($mPart !== $pathParts[$i]) {

                            $isMatch = false;
                            break;
                        }
                    }
                }

                if ($isMatch) {
                    self::$routeParams = $params;
                    return self::getFullRoutePath($controllerPath);
                }
            }
        }

        return false;
    }

    static private function getStaticController($path) {

        $apath = '';

        if ($path === '/') {
            $apath = self::$ROUTE_HOME;
        }
        else {
            // convert dash-case URL to camelCase file path
            $parts = explode('/', $path);
            $camelParts = [];

            foreach ($parts as $p) {
                $camelParts []= v($p)->u_camel_case();
            }

            $camelParts []= array_pop($camelParts);
            $apath = implode('/', $camelParts);
        }

        $thtPath = self::getFullRoutePath(Tht::getThtFileName($apath));

        if (!file_exists($thtPath)) {

            // Fall back to default.tht
            $thtPath = Tht::path('pages', Tht::getThtFileName('default'));

            // 404
            if (!file_exists($thtPath)) {
                Tht::module('Output')->u_send_error(404);
            }
        }

        return $thtPath;
    }

    static private function executeController ($controllerFile) {

        Tht::module('Perf')->u_start('tht.executeRoute', Tht::stripAppRoot($controllerFile));

        // Call a function directly e.g. `page @ myFunction`
        $userFunction = '';
        if (strpos($controllerFile, '@') !== false) {
            $parts = preg_split('/\s+@\s+/', $controllerFile, 2);
            if (count($parts) != 2) {
                Tht::configError("Please add space before AND after the `@` symbol: `$controllerFile`");
            }
            list($controllerFile, $userFunction) = $parts;
        }

        // Normalize windows paths
        $controllerFile = preg_replace('/\\\\/', '/', $controllerFile);
        $controllerFile = preg_replace('/^[A-Z]:/', '', $controllerFile);

        Compiler::process($controllerFile, true);

        self::callAutoFunction($controllerFile, $userFunction);

        Tht::module('Perf')->u_stop();
    }

    static private function callAutoFunction($controllerFile, $userFunction) {

        Tht::module('Perf')->u_start('tht.main', Tht::stripAppRoot($controllerFile));

        $nameSpace = ModuleManager::getNamespace(Tht::getFullPath($controllerFile));

        $callFunction = '';

        if ($userFunction) {

            // Function defined in app.jcon/routes
            // e.g. /foo: foo.tht @ someFunction

            $callFunction = $nameSpace . '\\u_' . v($userFunction)->u_slug('_');

            if (!function_exists($callFunction)) {

                $fullController = basename($controllerFile);
                Tht::configError("Function `$userFunction` not found for route target `$fullController`");
            }
        }
        else {
            $callFunction = self::getModeFunction($controllerFile, $nameSpace);
        }

        // Fall back to main()
        if (!$callFunction) {

            $fullModeFunction = $nameSpace . '\\u_main';

            if (function_exists($fullModeFunction)) {
                $callFunction = $fullModeFunction;
            }
        }

        if ($callFunction) {

            try {

                ErrorHandler::setMainEntryFunction($controllerFile, $callFunction);

                $ret = call_user_func($callFunction);

                if (!ONothing::isa($ret) && $ret) {
                    Tht::module('Output')->sendByType($ret);
                }

            } catch (ThtError $e) {

                ErrorHandler::handleThtRuntimeError($e, Tht::getPhpPathForTht($controllerFile));
            }
        }

        Tht::module('Perf')->u_stop();
    }

    static private function getModeFunction($controllerFile, $nameSpace) {

        // Don't auto-call mode functions for GET requests.
        if (Security::isGetRequest()) {
            return '';
        }

        $fullModeFunction = '';

        if (isset(self::$routeParams['mode'])) {

            // Route-based mode function
            // e.g. /page/foo --> fooMode()
            $modeParam = self::$routeParams['mode'];

            $fullModeFunction = self::resolveModeFunction($controllerFile, $nameSpace, $modeParam);
        }
        else {

            $modeParam = Tht::getPhpGlobal('post', 'mode', '');

            if ($modeParam) {

                // Param-based function
                // e.g. mode=foo --> fooMode()

                $fullModeFunction = self::resolveModeFunction($controllerFile, $nameSpace, $modeParam);
            }
            else {

                // Request Method
                // post --> postMode(), delete --> deleteMode()

                $reqMethodFunction = $nameSpace . '\\u_' . $reqMethod . '_Mode';

                if (function_exists($reqMethodFunction)) {
                    $fullModeFunction = $reqMethodFunction;
                }
            }
        }

        // TODO: Allow if /api/ is in route path?
        if ($fullModeFunction && Security::isRemoteOrigin()) {
            Tht::module('Output')->u_send_error(403, 'Remote Origin Not Allowed');
        }

        return $fullModeFunction;
    }

    static private function resolveModeFunction($controllerFile, $nameSpace, $rawName) {

        $modeName = v($rawName)->u_slug('_');
        $fullModeFunction = $nameSpace . '\\u_' . $modeName . '_Mode';

        if (!function_exists($fullModeFunction)) {

            if (Security::isDev()) {
                ErrorHandler::setFile($controllerFile);
                Tht::error("Mode function not found: `$inputName" . "Mode`");
            }
            else {
                Tht::module('Output')->u_send_error(404);
            }
        }

        return $fullModeFunction;
    }

    static public function getWebRouteParam ($key, $defaultVal = '') {

        if (!isset(self::$routeParams[$key])) {
            return $defaultVal;
        }

        return self::$routeParams[$key];
    }
}
