<?php namespace app\helpers;

class CommandLineHelper 
{
    /**
     * @param $cmd string
     * @throws CommandLineException
     * @return string output
     */
    public static function exec($cmd)
    {
        ob_start();
        passthru($cmd . ' 2>&1', $returnVal);
        $output = ob_get_clean();
        if ($returnVal) {
            throw new CommandLineException("'$cmd' returned code $returnVal with message: $output", $returnVal, $output);
        }

        return $output;
    }
}
