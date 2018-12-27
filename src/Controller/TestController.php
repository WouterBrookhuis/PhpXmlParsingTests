<?php
/**
 * Created by PhpStorm.
 * User: Brookhuis
 * Date: 27-12-2018
 * Time: 16:04
 */

namespace App\Controller;

use App\SuluXmlLoader;
use App\SuluXmlParser;
use App\XmlParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestController
{
    /**
     * @Route("/")
     * @param SuluXmlLoader $xmlLoader
     * @param SuluXmlParser $XMHParser
     * @return Response
     * @throws \Exception
     */
    public function index(SuluXmlLoader $xmlLoader, SuluXmlParser $XMHParser): Response
    {
        $path = 'Examples/Example1.xml';
        //$result = $xmlLoader->load(realpath($path));
        $altResult = $XMHParser->parseFile(realpath($path));
        return new JsonResponse($altResult);
        /*return new Response(
            '<pre>' . htmlspecialchars(print_r($result, true)) . '</pre>' .
            '<pre>' . htmlspecialchars(print_r($altResult, true)) . '</pre>'
        );*/
        /*return new Response(
            '<pre>' . htmlspecialchars(print_r($altResult, true)) . '</pre>'
        );*/
    }
}