<?php

/**
 * This file is part of the "dibi" - smart database abstraction layer.
 *
 * Copyright (c) 2005 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */



if (interface_exists('Nette\Diagnostics\IBarPanel')) {
	class_alias('Nette\Diagnostics\IBarPanel', 'IBarPanel');
}



/**
 * Dibi panel for Nette\Diagnostics.
 *
 * @author     David Grudl
 */
class DibiNettePanel extends DibiObject implements IBarPanel
{
	/** @var int maximum SQL length */
	static public $maxLength = 1000;

	/** @var bool  explain queries? */
	public $explain;

	/** @var int */
	public $filter;

	/** @var array */
	private $events = array();



	public function __construct($explain = TRUE, $filter = NULL)
	{
		$this->filter = $filter ? (int) $filter : DibiEvent::QUERY;
		$this->explain = (bool) $explain;
	}



	public function register(DibiConnection $connection)
	{
		if (is_callable('Nette\Diagnostics\Debugger::enable')) {
			class_alias('Nette\Diagnostics\Debugger', 'NDebugger'); // PHP 5.2 code compatibility
		}
		if (is_callable('NDebugger::enable')) {
			NDebugger::$bar->addPanel($this);
			NDebugger::$blueScreen->addPanel(array($this, 'renderException'), __CLASS__);
			$connection->onEvent[] = array($this, 'logEvent');
		} elseif (is_callable('Debugger::enable')) {
			Debugger::$bar->addPanel($this);
			Debugger::$blueScreen->addPanel(array($this, 'renderException'), __CLASS__);
			$connection->onEvent[] = array($this, 'logEvent');
		}
	}



	/**
	 * After event notification.
	 * @return void
	 */
	public function logEvent(DibiEvent $event)
	{
		if (($event->type & $this->filter) === 0) {
			return;
		}
		$this->events[] = $event;
	}



	/**
	 * Returns blue-screen custom tab.
	 * @return mixed
	 */
	public function renderException($e)
	{
		if ($e instanceof DibiException && $e->getSql()) {
			return array(
				'tab' => 'SQL',
				'panel' => dibi::dump($e->getSql(), TRUE),
			);
		}
	}



	/**
	 * Returns HTML code for custom tab. (Nette\Diagnostics\IBarPanel)
	 * @return mixed
	 */
	public function getTab()
	{
		return '<span title="dibi"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAQAAAC1+jfqAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAAEYSURBVBgZBcHPio5hGAfg6/2+R980k6wmJgsJ5U/ZOAqbSc2GnXOwUg7BESgLUeIQ1GSjLFnMwsKGGg1qxJRmPM97/1zXFAAAAEADdlfZzr26miup2svnelq7d2aYgt3rebl585wN6+K3I1/9fJe7O/uIePP2SypJkiRJ0vMhr55FLCA3zgIAOK9uQ4MS361ZOSX+OrTvkgINSjS/HIvhjxNNFGgQsbSmabohKDNoUGLohsls6BaiQIMSs2FYmnXdUsygQYmumy3Nhi6igwalDEOJEjPKP7CA2aFNK8Bkyy3fdNCg7r9/fW3jgpVJbDmy5+PB2IYp4MXFelQ7izPrhkPHB+P5/PjhD5gCgCenx+VR/dODEwD+A3T7nqbxwf1HAAAAAElFTkSuQmCC" />'
			. dibi::$numOfQueries . ' queries'
			. (dibi::$totalTime ? ' / ' . sprintf('%0.1f', dibi::$totalTime * 1000) . 'ms' : '')
			. '</span>';
	}



	/**
	 * Returns HTML code for custom panel. (Nette\Diagnostics\IBarPanel)
	 * @return mixed
	 */
	public function getPanel()
	{
		$s = NULL;
		$h = 'htmlSpecialChars';
		foreach ($this->events as $event) {
			$explain = NULL; // EXPLAIN is called here to work SELECT FOUND_ROWS()
			if ($this->explain && $event->type === DibiEvent::SELECT) {
				try {
					$backup = array($event->connection->onEvent, dibi::$numOfQueries, dibi::$totalTime);
					$event->connection->onEvent = NULL;
					$explain = dibi::dump($event->connection->nativeQuery('EXPLAIN ' . $event->sql), TRUE);
				} catch (DibiException $e) {}
				list($event->connection->onEvent, dibi::$numOfQueries, dibi::$totalTime) = $backup;
			}

			$s .= '<tr><td>' . sprintf('%0.3f', $event->time * 1000);
			if ($explain) {
				static $counter;
				$counter++;
				$s .= "<br /><a href='#' class='nette-toggler' rel='#nette-debug-DibiProfiler-row-$counter'>explain&nbsp;&#x25ba;</a>";
			}

			$s .= '</td><td class="nette-DibiProfiler-sql">' . dibi::dump(strlen($event->sql) > self::$maxLength ? substr($event->sql, 0, self::$maxLength) . '...' : $event->sql, TRUE);
			if ($explain) {
				$s .= "<div id='nette-debug-DibiProfiler-row-$counter' class='nette-collapsed'>{$explain}</div>";
			}
			if ($event->source) {
				$helpers = 'Nette\Diagnostics\Helpers';
				if (!class_exists($helpers)) {
					$helpers = class_exists('NDebugHelpers') ? 'NDebugHelpers' : 'DebugHelpers';
				}
				$s .= call_user_func(array($helpers, 'editorLink'), $event->source[0], $event->source[1])->class('nette-DibiProfiler-source');
			}

			$s .= "</td><td>{$event->count}</td><td>{$h($event->connection->getConfig('driver') . '/' . $event->connection->getConfig('name'))}</td></tr>";
		}

		return empty($this->events) ? '' :
			'<style> #nette-debug td.nette-DibiProfiler-sql { background: white !important }
			#nette-debug .nette-DibiProfiler-source { color: #999 !important }
			#nette-debug nette-DibiProfiler tr table { margin: 8px 0; max-height: 150px; overflow:auto } </style>
			<h1>Queries: ' . dibi::$numOfQueries . (dibi::$totalTime === NULL ? '' : ', time: ' . sprintf('%0.3f', dibi::$totalTime * 1000) . ' ms') . '</h1>
			<div class="nette-inner nette-DibiProfiler">
			<table>
				<tr><th>Time&nbsp;ms</th><th>SQL Statement</th><th>Rows</th><th>Connection</th></tr>' . $s . '
			</table>
			</div>';
	}

}
