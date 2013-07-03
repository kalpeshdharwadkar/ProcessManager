<?php

include './Daemon.php';
declare(ticks=1);
Daemon::run();

$work = true;
$isParent = getmypid();
$pm = new ProcessManager;

function getWorker() {
	$worker= new GearmanWorker();
	$worker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
	$worker->setTimeout(2500);
	$worker->addServer();
	$worker->addFunction("reverse", "my_reverse_function");
	$worker->addFunction('caps', "my_uppercase");
	return $worker;
}

function runWorker(GearmanWorker $worker) {
	global $work;
	echo "before work starts\n";
	$err = 0;
	while ( $work && ( $worker->work() || $worker->returnCode() == GEARMAN_IO_WAIT || $worker->returnCode() == GEARMAN_NO_JOBS)) {
			//echo "worked - {$worker->returnCode()}\n"; 
			if ($worker->returnCode() == GEARMAN_SUCCESS) continue;
			if (!$worker->wait()) {
				if ($worker->returnCode() == GEARMAN_NO_ACTIVE_FDS) {
					echo "Could not connect to server\n";
					if (5 < $err++) exit(1);
					sleep(2);
				} else $err = 0;
			}
	}
	var_dump($worker->error(), $worker->getErrno(), $worker->returnCode());
	//while ($work && $worker->work()) echo 'working'; 
	echo "all done\n";

}

class ProcessManager {

	private $managerPid;
	private $workerProcesses = [];

	function __construct() {
		$this->install_signals();
		$this->managerPid = getmypid();
		$this->spawnWorkers();
		$this->manageWorkers();
	}

	private function manageWorkers() {
		while (1) {
			// Do nothing other than wait for SIGTERM/SIGINT
			sleep(5);
		}
	}

	function install_signals() {
		echo getmypid() . " SIGTERM handler installation\n";
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
	}

	function signal($signo) {
		if (getmypid() == $this->managerPid) {
			echo 'Parent got sigterm'."\n";
			var_dump($this->workerProcesses);
			$this->stop_children(SIGTERM);
			while ($this->workerProcesses) {
				$status = null;
				$exited = pcntl_wait($status, WNOHANG);
				echo "WNOHANG Exited = $exited\n";
				sleep(1);
				unset($this->workerProcesses[$exited]);
			}
			echo "Parent shutting down\n";
			exit;
		}
		else {
			echo 'Child got sigterm'."\n";
			global $work;
			$work = false;
		}
	}

	function stop_children($sig = SIGTERM) {
		foreach ($this->workerProcesses as $pid) {
			echo "Sending SIGTERM to $pid\n";
			posix_kill($pid, $sig);
			if (!posix_kill($pid, 0)) {
				echo "$pid is dead already\n";
			}
		}
	}

	private function spawnWorkers() {
		for ($i = 0; $i < 2; $i++) {
			switch ($pid = pcntl_fork()) {
			case -1: echo "Forking failed"; exit(2);
			case 0: 
				$myPid = getmypid();
				echo "I'm the child, my PID = $myPid\n";
				$this->install_signals();
				runWorker(getWorker());
				exit;
				break;
			default:
				echo "Parent forked into pid $pid\n";
				$this->workerProcesses[$pid] = $pid;
				break;
			}
		}
		
	}

}

function my_reverse_function($job)
{
  return strrev($job->workload());
}

function my_uppercase(GearmanJob $job) {
        return strtoupper($job->workload());
}

