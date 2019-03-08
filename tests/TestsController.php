<?php

namespace placer\brio\tests;

use mako\http\routing\Controller;
use mako\view\ViewFactory;
use mako\utility\Str;

/**
*  $routes->group(['namespace' => 'placer\brio\tests'], function($routes)
*  {
*      $routes->get('/tests/{slug}?', 'TestsController::index');
*  });
*/
class TestsController extends Controller
{
	/**
	 * Index Action
	 * @param  ViewFactory $view
	 * @param  string      $slug
	 * @return string
	 */
	public function index(ViewFactory $view, $slug = 'autoescape'): string
	{
		$stubs = $this->getStubs();
		$view->assign('stub', $slug);
		$view->assign('pages', $stubs);
		$view->assign('title', $stubs[$slug]);

		$data = $this->getData($slug);
		$view->assign($key = key($data), $data[$key]);

		return $view->render('brio::tests');
	}

	/**
	 * Get Stubs
	 * @return array
	 */
	protected function getStubs(): array
	{
		$path = dirname(__DIR__) . '/resources/views/stubs';
		$templateFiles = array_diff(scandir($path), ['..', '.']);

		if (empty($templateFiles))
			return [];

		$stubs = [];
		foreach ($templateFiles as $file)
		{
			$fileName = str_replace('.html.twig', '', $file);

			if (strpos($fileName, '_') === 0)
				continue;

			$stubs[$fileName] = ucfirst(Str::underscored2camel($fileName));
		}

		return $stubs;
	}

	/**
	 * Get View data
	 * @param  string $slug
	 * @return array
	 */
	protected function getData(string $slug): array
	{
		switch ($slug)
		{
			case 'autoescape':
				return ['variable' => '<b>foo</b>'];
			case 'cycle':
				return ['items' => [1,2,3,4,5,6]];
			case 'loop_empty':
				return ['items' => []];
			case 'explode':
				return ['text' => 'foo,bar'];
			case 'expr':
				return ['user' => [
			        'status'  => 'active',
			        'pending' => true,
			        'banned'  => false,
			    	],
			    ];
			case 'filter':
				return ['var' => '"value"'];
			case 'for_range_minimax':
				return ['e' => [
					'min' => 5,
					'max' => 1,
					],
				];



			default:
				return ['' => ''];
		}
	}

}
