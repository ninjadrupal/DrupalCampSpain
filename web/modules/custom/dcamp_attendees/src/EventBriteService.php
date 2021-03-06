<?php

namespace Drupal\dcamp_attendees;

use Drupal\dcamp_attendees\Entity\Attendee;

class EventBriteService {

  /**
   * The cache id to store the list of attendees.
   *
   * @var string
   */
  protected $attendees_cid = 'dcamp_attendees:eventbrite_attendees';

  /**
   * The time to keep the list of attendees cached.
   *
   * Notice that you need to use this as strtotime($this->attendees_cid)
   * to set the right expiration time..
   *
   * @var string
   */
  protected $attendees_lifetime = '+5 minutes';

  /**
   * Return the list of individual sponsors
   *
   * @return \Drupal\dcamp_attendees\Entity\Attendee[]
   *   Array of Attendee objects
   */
  public function getIndividualSponsors() {
    $attendees_raw = $this->doGetAttendees();

    // Extract individual sponsors from the list of attendees.
    $individual_sponsors = [];
    foreach ($attendees_raw as $attendee) {
      if (in_array($attendee->ticket_class_name, ['Patrocinador individual', 'Patrocinador individual SIN entrada'])) {
        $individual_sponsors[] = new Attendee($attendee);
      }
    }

    return $individual_sponsors;
  }

  /**
   * Return the list of attendees
   *
   * @return \Drupal\dcamp_attendees\Entity\Attendee[]
   *   Array of Attendee objects
   */
  public function getAttendees() {
    $attendees_raw = $this->doGetAttendees();

    // Remove individual sponsors who did not get a ticket from the list.
    $attendees = [];
    foreach ($attendees_raw as $attendee) {
      if ($attendee->ticket_class_name != 'Patrocinador individual SIN entrada') {
        $attendees[] = new Attendee($attendee);
      }
    }

    return $attendees;
  }

  /**
   * Request the list of attendees to Eventbrite.
   *
   * @return array
   *   The raw array of attendees from Eventbrite.
   */
  protected function doGetAttendees() {
    $config = \Drupal::config('dcamp_attendees.settings');
    $attendees_list = [];

    // Check if we are in developer mode.
    if ($config->get('debugging')) {
      $path = \Drupal::service('module_handler')->getModule('dcamp_attendees')->getPath();
      $fixture_data = json_decode(file_get_contents($path . '/fixtures/attendees.json'));
      $attendees_list = $fixture_data->attendees;
    }
    else {
      // Check if there is a cached value and it has not expire.
      $data = NULL;
      if ($cache = \Drupal::cache()->get($this->attendees_cid)) {
        $attendees_list = $cache->data->attendees;
      }
      if (($cache == FALSE) || ($cache->expire < time())) {
        // Eventbrite returns paged responses. Go through every page and load
        // attendee_data into a single array.
        $eventbrite_data = $this->loadAllAttendees();
        $attendees_list = $eventbrite_data->attendees;

        // Store this data in cache.
        \Drupal::cache()->set($this->attendees_cid, $eventbrite_data, strtotime($this->attendees_lifetime));
      }
    }

    // Filter out attendees who cancelled his ticket.
    $attendees_list = array_filter($attendees_list, function($attendee_data) {
      return $attendee_data->cancelled == FALSE;
    });

    return $attendees_list;
  }

  /**
   * Loads all attendees from Eventbrite recursively.
   *
   * @param stdClass $eventbrite_data
   *   The response from the request of the previous page, which is used
   *   to accumulate results.
   *
   * @return stdClass
   *   An stdClass object containing the whole list of attendees at the
   *   attendees property, plus pagination data from the last page at
   *   the pagination property.
   */
  protected function loadAllAttendees($eventbrite_data = NULL) {
    $config = \Drupal::config('dcamp_attendees.settings');
    // Request the list of attendees to EventBrite and filter individual sponsors.
    $client = \Drupal::httpClient();
    $page = 1;
    if (!empty($eventbrite_data)) {
      $page = $eventbrite_data->pagination->page_number;
    }
    $uri = 'https://www.eventbriteapi.com/v3/events/' . $config->get('event_id') . '/attendees?page=' . $page;
    $response = $client->request('GET', $uri, [
      'headers' => [
        'Authorization' => 'Bearer ' . $config->get('oauth_token'),
      ]
    ]);

    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Bad response from EventBrite');
    }

    // Decode and extract pagination and attendees.
    $response_data = json_decode($response->getBody());
    if (empty($eventbrite_data)) {
      $eventbrite_data = $response_data;
    }
    else {
      $eventbrite_data->attendees = array_merge($eventbrite_data->attendees, $response_data->attendees);
      $eventbrite_data->pagination = $response_data->pagination;
    }

    // Check if we need to request the next page.
    if ($response_data->pagination->page_number < $response_data->pagination->page_count) {
      $eventbrite_data->pagination->page_number++;
      $eventbrite_data = $this->loadAllAttendees($eventbrite_data);
    }

    return $eventbrite_data;
  }
}