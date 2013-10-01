<?php namespace app\helpers;

class CommandLineException extends \RuntimeException
{
    /** @var string */
    protected $output;

    /** @var int */
    protected $exitCode;

    public function __construct($message = "", $exitCode = 0, $output = '', \Exception $previous = null)
    {
        $this->exitCode = $exitCode;
        $this->output = $output;
        parent::__construct($message, 0, $previous);
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function getExitCode()
    {
        return $this->exitCode;
    }
}
