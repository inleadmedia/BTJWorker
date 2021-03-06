<?php

namespace BTJ\Scrapper\Service;

use BTJ\Scrapper\Transport\HttpTransportInterface;
use BTJ\Scrapper\Container\EventContainerInterface;
use BTJ\Scrapper\Container\LibraryContainerInterface;
use BTJ\Scrapper\Container\NewsContainerInterface;

/**
 * Class CSLibraryService.
 */
class CSLibraryService extends ScrapperService implements ConfigurableServiceInterface
{

    use ConfigurableServiceTrait;

    /**
     * CSLibraryService constructor.
     *
     * @param \BTJ\Scrapper\Transport\HttpTransportInterface $transport
     *   HTTP request transport.
     */
    public function __construct(HttpTransportInterface $transport, $limit = 50) {
      $this->identifier = 'cslibrary';
      $this->setScrapingLimit($limit);

      parent::__construct($transport);
    }

    /**
     * {@inheritdoc}
     */
    public function getEventsLinks($url) : array {
          $list = [];
          $events = [];
          $crawler = $this->getTransport()->request('GET', $url . "/calendar/html");
          $links = $crawler->filter('a.page-link')->links();
          foreach ($links as $link) {
              $events[] = $link->getURI();
          }

          foreach ($events as $event) {
              $crawler = $this->getTransport()->request('GET', $event);
              $occurrences = $crawler->filter('#all-occurrences-list a')->links();
              foreach ($occurrences as $occurrence) {
                  $uri = $occurrence->getURI();
                  $list[md5($uri)] = $uri;

                  if (count($list) >= $this->getScrapingLimit()) {
                      break(2);
                  }
              }
          }

          return $list;
      }

    /**
     * {@inheritDoc}
     */
    public function getNewsLinks($url) : array
    {
        $list = [];
        $crawler = $this->getTransport()->request('GET', $url . '/search/content/html?fType=news');
        $links = $crawler->filter('a.page-link')->links();
        foreach ($links as $link) {
            $uri = $link->getURI();
            $list[md5($uri)] = $uri;

            if (count($list) >= $this->getScrapingLimit()) {
                break;
            }
        }

        return $list;
    }

    /**
     * {@inheritDoc}
     */
    public function getLibrariesLinks($url) : array
    {
        $list = [];
        $crawler = $this->getTransport()->request('GET', $url . '/opening-hours?culture=sv');
        $links = $crawler->filter('a.library-name')->links();
        foreach ($links as $link) {
            $uri = $link->getURI();
            $list[md5($uri)] = $uri;

            if (count($list) >= $this->getScrapingLimit()) {
                break;
            }
        }

      return $list;
    }

    /**
     * {@inheritDoc}
     */
    public function eventScrap(string $url, EventContainerInterface $container) : void
    {
        $crawler = $this->getTransport()->request('GET', $url);

        $container->setURL($url);

        $crawler->filter('h1.page-title')->each(function ($node) use ($container) {
            $container->setTitle($node->text());
        });

        $crawler->filter('.page-introduction')->each(function ($node) use ($container) {
            $container->setLead($node->text());
        });

        $crawler->filter('.template .zone-1')->each(function ($node) use ($container) {
            $children = $node->children()->each(function ($child) {
                if ($child->nodeName() == 'script') {
                    unset($child);
                }
                if (!empty($child)) {
                    return $child->html();
                }
            });

            $children = array_filter($children, function ($child) {
                if (!empty($child)) {
                    if (strpos($child, '<strong>') === false &&
                      strpos($child, 'page-introduction') === false &&
                      strpos($child, 'event-audience') === false &&
                      strpos($child, 'event-info') === false) {
                        return $child;
                    }
                }
            });
            $container->setBody(implode('', $children));
        });

        $crawler->filter('.editorial-image > img.front-img')->each(function ($node) use ($container) {
            $container->setListImage($node->attr('src'));
        });

        $crawler->filter('.editorial-image > .content-type')->each(function ($node) use ($container) {
            $container->setCategory($node->text());
        });

        $crawler->filter('.selected-occurence .event-date')->each(function ($node) use ($container) {
            $container->setDate($node->text());
        });

        $crawler->filter('.selected-occurence .event-month')->each(function ($node) use ($container) {
            $container->setMonth($node->text());
        });

        $crawler->filter('.selected-occurence .event-when > .value')->each(function ($node) use ($container) {
            $container->setTime($node->text());
        });

        $crawler->filter('.library-name > .page-link')->each(function ($node) use ($container) {
            $container->setLibrary($node->text());
        });

        $crawler->filter('.event-cost > .value')->each(function ($node) use ($container) {
            $container->setPrice((int) $node->text());
        });

        $targets = $crawler->filter('li.audience-value')->each(function ($node) {
            return trim(preg_replace('/\s+/', ' ', $node->text()));
        });
        $container->setTarget($targets);

        $tags = $crawler->filter('.tags li')->each(function ($node) {
            return trim(preg_replace('/\s+/', ' ', $node->text()));
        });
        $container->setTags($tags);
    }

    /**
     * {@inheritDoc}
     */
    public function newsScrap(string $url, NewsContainerInterface $container) : void
    {
        $crawler = $this->getTransport()->request('GET', $url);

        $container->setURL($url);

        $crawler->filter('h1.page-title')->each(function ($node) use ($container) {
            $container->setTitle($node->text());
        });

        $crawler->filter('.page-introduction')->each(function ($node) use ($container) {
            $container->setLead($node->text());
        });

        $crawler->filter('.content-image > img')->each(function ($node) use ($container) {
            $container->setListImage($node->attr('src'));
        });

        $crawler->filter('.image-widget > img')->each(function ($node) use ($container) {
            $container->setTitleImage($node->attr('src'));
        });

        $body = $crawler->filter('.template .zone-1')->first()->html();
        $container->setBody($body);

        $targets = $crawler->filter('.audiences a')->each(function ($node) {
            return trim(preg_replace('/\s+/', ' ', $node->text()));
        });
        $container->setTarget($targets);

        $tags = $crawler->filter('.tags li')->each(function ($node) {
            return trim(preg_replace('/\s+/', ' ', $node->text()));
        });
        $container->setTags($tags);
    }


    /**
     * {@inheritDoc}
     */
    public function libraryScrap(string $url, LibraryContainerInterface $container) : void
    {
        $crawler = $this->getTransport()->request('GET', $url);

        $container->setURL($url);

        $title = $crawler->filter('h1.page-title')->first()->text() ?? '';
        $container->setTitle($title);

        $image = $crawler->filter('.content-image > img')->first()->attr('src') ?? '';
        $matches = [];
        preg_match('/.*\/(.*)/', $image, $matches);
        $url = parse_url($image);
        $image_url = $url['scheme'] . '://' . $url['host'] . '/images/' . $matches[1];
        $container->setTitleImage($image_url);

        $crawler->filter('.template .zone-1')->each(function ($node) use ($container) {
            $children = $node->children()->each(function ($child) {
                if ($child->nodeName() == 'script') {
                    unset($child);
                }
                if (!empty($child)) {
                    return $child->html();
                }
            });

            $children = array_filter($children, function ($child) {
                if (!empty($child)) {
                    if (strpos($child, '<strong>') === false &&
                    strpos($child, 'code-widget') === false &&
                    strpos($child, 'image-widget') === false &&
                    strpos($child, 'event-occurence-widget') === false &&
                    strpos($child, 'facebook') === false) {
                        return $child;
                    }
                }
            });
            $container->setBody(implode('', $children));
        });

        $node = $crawler->filter('.email a')->first();
        $email = $node->getNode(0) ? $node->text() : '';
        $container->setEmail($email);

        $node = $crawler->filter('.phone')->last();
        $phone = $node->getNode(0) ? $node->text() : '';
        $container->setPhone($phone);

        $node = $crawler->filter('.street')->first();
        $street = $node->getNode(0) ? $node->text() : '';
        $container->setStreet($street);

        $node = $crawler->filter('.zip-code')->first();
        $zip = $node->getNode(0) ? $node->text() : '';
        $container->setZip($zip);

        $node = $crawler->filter('.city')->first();
        $city = $node->getNode(0) ? $node->text() : '';
        $container->setCity($city);

        $day_map = [
          'söndag' => 0,
          'måndag' => 1,
          'tisdag' => 2,
          'onsdag' => 3,
          'torsdag' => 4,
          'fredag' => 5,
          'lördag' => 6,
        ];
        $days = $crawler->filter('.week-day')->each(function ($node) use ($day_map) {
            return $day_map[$node->text()];
        });

        $hours = $crawler->filter('ol.hours-list li:first-of-type')->each(function ($node) {
            return $node->text();
        });
        $hours = array_combine($days, $hours);
        $container->setOpeningHours($hours);
    }
}
