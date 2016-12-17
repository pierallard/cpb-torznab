<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends Controller
{
    const T411TORZNAB_URL = 'http://localhost:9876/';

    /**
     * @Route("/api", defaults={"_format"="xml"})
     */
    public function apiAction(Request $request)
    {
        $rid    = $request->get('rid');
        $ep     = $request->get('ep');
        $season = $request->get('season');
        $q      = $request->get('q');

        $serieName = $this->getSerieName($q, $rid);
        if ($serieName) {
            $results = $this->getResults($serieName, $season, $ep);
        } else {
            $results = [[
                'title' => 'Foo',
                'href' => 'Bar',
                'torrent' => 'Torrent.torrent',
                'size' => 42
            ]];
        }

        $xmlResults = '';
        foreach ($results as $item) {
            $xmlResult = file_get_contents(__DIR__ . '/../Xml/item.xml');
            $xmlResult = str_replace('%%title%%', $item['title'], $xmlResult);
            $xmlResult = str_replace('%%href%%', $item['href'], $xmlResult);
            $xmlResult = str_replace('%%torrent%%', $item['torrent'], $xmlResult);
            $xmlResult = str_replace('%%size%%', $item['size'], $xmlResult);

            $xmlResults .= $xmlResult;
        }

        $xml = file_get_contents(__DIR__ . '/../Xml/api.xml');
        $xml = str_replace('<!-- results -->', $xmlResults, $xml);
        $xml = str_replace('%%count%%', count($results), $xml);

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'xml');

        return $response;
    }

    /**
     * @Route("/", name="search")
     */
    public function searchAction(Request $request)
    {
        $form = $this->createFormBuilder()
            ->add('search', TextType::class)
            ->add('save', SubmitType::class, array('label' => 'Go go go!'))
            ->getForm();

        $form->handleRequest($request);

        $results = [];

        if ($form->isSubmitted()) {
            $task = $form->getData();
            $search = $task['search'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::T411TORZNAB_URL . 'api?t=search&q=' . \URLify::filter($search));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $result = curl_exec($ch);
            curl_close($ch);

            $xml = new \SimpleXMLElement($result);
            $firstNode = $xml->channel;
            foreach ($firstNode->item as $item) {
                $results[] = [
                    'title' => $item->title,
                    'guid'  => $item->guid,
                ];
            };
        }

        return $this->render('search.html.twig', array(
            'form' => $form->createView(),
            'results' => $results
        ));
    }

    /**
     * @Route("/download/{id}")
     */
    public function downloadAction(Request $request)
    {
        $id = $request->get('id');
        $torrent = file_get_contents(self::T411TORZNAB_URL . 'torrent/' . $id);
        $filename = '/tmp/' . $request->get('id') . '.torrent';
        file_put_contents($filename, $torrent);

        $cmd = "transmission-remote -n 'transmission:transmission' -a " . $filename;
        shell_exec($cmd);

        return $this->redirectToRoute('search');
    }

    /**
     * @param $q
     * @param $rid
     *
     * @return string
     */
    protected function getSerieName($q, $rid)
    {
        if (('' !== $q) && (null !== $q)) {
            return $q;
        }

        if (('' !== $rid) && (null !== $rid)) {
            $json = file_get_contents('http://api.tvmaze.com/lookup/shows?tvrage=' . $rid);
            $obj = json_decode($json);

            return $obj->name;
        }

        return null;
    }

    /**
     * @param $serieName
     * @param $season
     * @param $ep
     *
     * @return array
     */
    protected function getResults($serieName, $season, $ep)
    {
        $url = sprintf('http://www.cpasbien.cm/recherche/%s.html', \URLify::filter(
            sprintf('%s S%02dE%02d', $serieName, $season, $ep)
        ));
        $dom = \pQuery::parseFile($url);
        $items = $dom->query('.ligne0, .ligne1');

        $results = [];
        foreach ($items as $item) {
            $results[] = [
                'title' => $item->query('a.titre')->text(),
                'href' => $item->query('a.titre')->attr('href'),
                'torrent' => $item->query('a.titre')->attr('torrent'),
                'size' => $this->getSize($item->query('a.titre')->attr('href'))
            ];
        }

        return $results;
    }

    /**
     * @param $url
     *
     * @return integer
     */
    protected function getSize($url)
    {
        $dom = \pQuery::parseFile($url);
        $humanTxt = $dom->query('#infosficher span')->text();
        $val = floatval($humanTxt);
        if (strpos($humanTxt, ' Ko') !== false) {
            $val = $val * 1024;
        } elseif (strpos($humanTxt, ' Mo') !== false) {
            $val = $val * 1024 * 1024;
        } elseif (strpos($humanTxt, ' Go') !== false) {
            $val = $val * 1024 * 1024 * 1024;
        }

        return intval($val);
    }
}
