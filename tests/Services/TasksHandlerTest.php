<?php
namespace Rocketeer\Services;

use Rocketeer\Facades\Rocketeer;
use Rocketeer\TestCases\RocketeerTestCase;

class TasksHandlerTest extends RocketeerTestCase
{
	public function testCanAddCommandsToArtisan()
	{
		$command = $this->tasksQueue()->add('Rocketeer\Tasks\Deploy');
		$this->assertInstanceOf('Rocketeer\Console\Commands\BaseTaskCommand', $command);
		$this->assertInstanceOf('Rocketeer\Tasks\Deploy', $command->getTask());
	}

	public function testCanUseFacadeOutsideOfLaravel()
	{
		Rocketeer::before('deploy', 'ls');
		$before = Rocketeer::getTasksListeners('deploy', 'before', true);

		$this->assertEquals(['ls'], $before);
	}

	public function testCanGetTasksBeforeOrAfterAnotherTask()
	{
		$task   = $this->task('Deploy');
		$before = $this->tasksQueue()->getTasksListeners($task, 'before', true);

		$this->assertEquals(['before', 'foobar'], $before);
	}

	public function testCanAddTasksViaFacade()
	{
		$task   = $this->task('Deploy');
		$before = $this->tasksQueue()->getTasksListeners($task, 'before', true);

		$this->tasksQueue()->before('deploy', 'composer install');

		$newBefore = array_merge($before, array('composer install'));
		$this->assertEquals($newBefore, $this->tasksQueue()->getTasksListeners($task, 'before', true));
	}

	public function testCanAddMultipleTasksViaFacade()
	{
		$task  = $this->task('Deploy');
		$after = $this->tasksQueue()->getTasksListeners($task, 'after', true);

		$this->tasksQueue()->after('deploy', array(
			'composer install',
			'bower install'
		));

		$newAfter = array_merge($after, array('composer install', 'bower install'));
		$this->assertEquals($newAfter, $this->tasksQueue()->getTasksListeners($task, 'after', true));
	}

	public function testCanRegisterCustomTask()
	{
		$this->swapConfig(array(
			'rocketeer::default' => 'production',
		));

		$this->tasks->task('foobar', function ($task) {
			$task->runForCurrentRelease('ls');
		});

		$this->assertInstanceOf('Rocketeer\Tasks\Closure', $this->builder->buildTask('foobar'));

		$this->tasks->run('foobar');
		$this->assertHistory([['cd {server}/releases/{release}', 'ls']]);
	}

	public function testCanRegisterCustomTaskViaArray()
	{
		$this->swapConfig(array(
			'rocketeer::default' => 'production',
		));

		$this->tasks->task('foobar', ['ls', 'ls']);
		$this->assertInstanceOf('Rocketeer\Tasks\Closure', $this->builder->buildTask('foobar'));

		$this->tasks->run('foobar');
		$this->assertHistory([['cd {server}/releases/{release}', 'ls', 'ls']]);
	}

	public function testCanAddSurroundTasksToNonExistingTasks()
	{
		$task = $this->task('Setup');
		$this->tasksQueue()->after('setup', 'composer install');

		$after = array('composer install');
		$this->assertEquals($after, $this->tasksQueue()->getTasksListeners($task, 'after', true));
	}

	public function testCanAddSurroundTasksToMultipleTasks()
	{
		$this->tasksQueue()->after(array('cleanup', 'setup'), 'composer install');

		$after = array('composer install');
		$this->assertEquals($after, $this->tasksQueue()->getTasksListeners('setup', 'after', true));
		$this->assertEquals($after, $this->tasksQueue()->getTasksListeners('cleanup', 'after', true));
	}

	public function testCangetTasksListenersOrAfterAnotherTaskBySlug()
	{
		$after = $this->tasksQueue()->getTasksListeners('deploy', 'after', true);

		$this->assertEquals(array('after', 'foobar'), $after);
	}

	public function testCanAddEventsWithPriority()
	{
		$this->tasksQueue()->before('deploy', 'second', -5);
		$this->tasksQueue()->before('deploy', 'first');

		$listeners = $this->tasksQueue()->getTasksListeners('deploy', 'before', true);
		$this->assertEquals(['before', 'foobar', 'first', 'second'], $listeners);
	}

	public function testCanExecuteContextualEvents()
	{
		$this->swapConfig(array(
			'rocketeer::stages.stages'            => array('hasEvent', 'noEvent'),
			'rocketeer::on.stages.hasEvent.hooks' => array('before' => array('check' => 'ls')),
		));

		$this->connections->setStage('hasEvent');
		$this->assertEquals(['ls'], $this->tasksQueue()->getTasksListeners('check', 'before', true));

		$this->connections->setStage('noEvent');
		$this->assertEquals([], $this->tasksQueue()->getTasksListeners('check', 'before', true));
	}

	public function testCanbuildTasksFromConfigHook()
	{
		$tasks = array(
			'npm install',
			'bower install',
		);

		$this->swapConfig(array(
			'rocketeer::hooks' => ['after' => ['deploy' => $tasks]],
		));

		$this->tasksQueue()->registerConfiguredEvents();
		$listeners = $this->tasksQueue()->getTasksListeners('deploy', 'after', true);

		$this->assertEquals($tasks, $listeners);
	}

	public function testCanHaveCustomConnectionHooks()
	{
		$tasks = array(
			'npm install',
			'bower install',
		);

		$this->swapConfig(array(
			'rocketeer::default'                      => 'production',
			'rocketeer::hooks'                        => [],
			'rocketeer::on.connections.staging.hooks' => ['after' => ['deploy' => $tasks]],
		));
		$this->tasksQueue()->registerConfiguredEvents();

		$this->connections->setConnection('production');
		$events = $this->tasksQueue()->getTasksListeners('deploy', 'after', true);
		$this->assertEmpty($events);

		$this->connections->setConnection('staging');
		$events = $this->tasksQueue()->getTasksListeners('deploy', 'after', true);

		$this->assertEquals($tasks, $events);
	}
}