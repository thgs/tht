<?php

namespace o;

class SourceAnalyzer {

    private $file = '';

    function __construct($thtFile) {
        $this->file = $thtFile;
    }

    function getCurrentStats() {

        $phpFile = Tht::getPhpPathForTht($this->file);

        if (!file_exists($phpFile)) {
            return $this->emptyStats();
        }

        $fh = fopen($phpFile, "r");
        if (!$fh) { return false; }

        while (true) {
            $line = fgets($fh);
            if ($line === false) {  break;  }
            if (strpos($line, '/* STATS') !== false) {
                preg_match('#STATS=({.*}) \*/#', $line, $m);
                return json_decode($m[1], true);
            }
        }

        fclose($fh);

        return $this->emptyStats();
    }

    function emptyStats() {

        return [
            // file stats
            'numLines' => 0,
            'numFunctions' => 0,
            'numLinesPerFunction' => 0,
            'longestFunctionLines' => 0,
            'longestFunctionName' => '',
            'longestFunctionLineNum' => 0,
            'numAdvancedFeatures' => 0,

            // work stats
            'numCompiles' => 0,
            'totalWorkTime' => 0,
            'lastCompileTime' => time(),
        ];
    }

    function analyze($getComment = false) {

        $stats = $this->getQualityStats();

        $stats = $this->updateWorkStats($stats);

        if ($getComment) {
            return "/* STATS=" . json_encode($stats) . " */\n";
        } else {
            return $stats;
        }
    }

    function getQualityStats() {

        $stats = $this->emptyStats();

        $thtFile = $this->file;

        $numFunctionLines = 0;
        $inFunction = false;
        $inFunctionName = '';
        $inFunctionLines = 0;

        $fh = fopen($thtFile, "r");
        if (!$fh) { return false; }

        $lineNum = -1;

        while (true) {

            $line = fgets($fh);
            if ($line === false) {  break;  }

            $lineNum += 1;

            $line = trim($line);

            // skip blank
            if (!preg_match('#\S#', $line)) {
                continue;
            }
            // skip comment
            else if (preg_match('#^//#', $line)) {
                continue;
            }
            // skip lines that are only symbols. e.g. '})'
            else if (!preg_match('#[a-zA-Z0-9]#', $line)) {
                continue;
            }

            $stats['numLines'] += 1;

            if (preg_match('#(&&=|&&:|\|\|=|\|\|:|\.\.\.\$|x\{)#', $line)) {
                $stats['numAdvancedFeatures'] += 1;
            }

            if (preg_match('#^tm\b#', $line, $m)) {
                // Don't count templates
                $inFunction = false;
            }
            else if (preg_match('#^(public\s+|)fn\s+(\w+)#', $line, $m)) {
                $inFunction = true;
                $inFunctionName = $m[2];
                $inFunctionLines = 0;
                $stats['numFunctions'] += 1;
            }
            else if ($inFunction) {
                $inFunctionLines += 1;
                $numFunctionLines += 1;
                if ($inFunctionLines > $stats['longestFunctionLines']) {
                    $stats['longestFunctionLines'] = $inFunctionLines;
                    $stats['longestFunctionName'] = $inFunctionName;
                    $stats['longestFunctionLineNum'] = $lineNum;
                }
            }
        }

        fclose($fh);

        if ($stats['numFunctions']) {
            $stats['numLinesPerFunction'] = round($numFunctionLines / $stats['numFunctions'], 1);
        }

        return $stats;
    }

    function updateWorkStats($stats) {

        $prevStats = $this->getCurrentStats($this->file);

        if (!isset($prevStats['numCompiles'])) {
            $prevStats = $this->emptyStats();
        }

        $stats['numCompiles'] = $prevStats['numCompiles'] + 1;

        $timeSinceLastCompile = time() - $prevStats['lastCompileTime'];
        $workTime = ($timeSinceLastCompile <= 10 * 60) ? $timeSinceLastCompile : 0;
        $stats['totalWorkTime'] = $prevStats['totalWorkTime'] + $workTime;
        $stats['lastCompileTime'] = time();

        return $stats;
    }

}