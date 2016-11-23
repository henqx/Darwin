<?php
/*
    The Darwin class contains functions for 
        - Fetching stats for individual combinations, grouped by both creative and block fields, as well as aggregated statisticons.
        - Battling these combinations on both individual and aggregate level (essentially determining which combinations lose the most -> which combinations are the worst)
        - Updating/inserting the database in accordance with the results from these battles
*/
class Darwin {
  var $con;
  var $campaigns;
  var $agg;
  var $aggAux;
  var $result;
  var $aux;
  var $limits;
  var $required;
  var $creatives_whitelist;
  var $autoresponders;
  var $timer;
  var $logger;
  
  function __construct($con, $timer, $logger) {
    $this->con = $con;
    $this->logger = $logger;
    $this->timer = $timer;
    $this->required = (object) [
      'sender_name' => 2,
      'subject' => 3,
      'description' => 3,
    ];
    //Starting date for which data is used (i.e. data is only fetched after this date)
    $this->starting_date = '2016-06-30 00:00:00'; // '2016-10-18 00:00:00'
    
    //Get the open_limits on a domain basis (used for detemrining when an aggregated combination is not performant enough)
    $q = "SELECT *
          FROM domains";
    $res = q($this->con, $q);
    while($row = mysqli_fetch_assoc($res)) {
      $this->limits[$row['name']] = $row['darwin_open_limit'];
    }
    
    //Get all currently active autoresponder campaigns
    $q = "SELECT campaign_id
          FROM autoresponders
          GROUP BY campaign_id";
    $res = q($this->con, $q);
    while($row = mysqli_fetch_assoc($res)) {
      $this->autoresponders[] = $row['campaign_id'];
    }

  }

  //Fetch all main stats
  public function getStats() {
    //Get creative stats
    $q = "SELECT SUM(sends) AS sends, 
                 SUM(opens) AS opens, 
                 SUM(clicks) AS clicks, 
                 SUM(commission) AS commission, 
                 SUM(unsubs) AS unsubs, 
                 SUM(spam_reps) AS spam_reps, 
                 SUM(own_spam_reps) AS own_spam_reps, 
                 description, sender_name, subject, preheader, CT.campaign_id, date, domain
          FROM (
            SELECT COUNT(*) AS sends, 0 AS opens, 0 AS clicks, 0 AS commission, 0 AS unsubs, 0 AS spam_reps, 0 AS own_spam_reps, description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader
            
            UNION ALL
            
            SELECT 0, COUNT(*), 0, 0, 0, 0, 0, description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN (SELECT ref FROM opens GROUP BY ref) opens
            ON sends.ref = opens.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader
            
            UNION ALL
            
            SELECT 0, 0, COUNT(*), 0, 0, 0, 0, description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN (SELECT ref FROM clicks GROUP BY ref) clicks
            ON sends.ref = clicks.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader

            UNION ALL

            SELECT 0, 0, 0, SUM(amount), 0, 0, 0, description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM conversions
            INNER JOIN sends
            ON sends.ref = conversions.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE rejected IS NULL
            AND sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader
            
            UNION ALL

            SELECT 0, 0, 0, 0, COUNT(*), 0, 0, description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN subscribers
            ON sends.ref = subscribers.unsub_reason
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader

            UNION ALL

            SELECT 0, 0, 0, 0, 0, COUNT(*), 0, description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN spam_reports
            ON spam_reports.ref = sends.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader

            UNION ALL

            SELECT 0, 0, 0, 0, 0, 0, COUNT(*), description, sender_name, subject, sends.meta, sends.preheader, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN own_spam_reports
            ON own_spam_reports.ref = sends.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader
          ) CT
          INNER JOIN auction_creatives AS sender_name
          ON (sender_name.id = CT.sender_name AND sender_name.type = 'sender_name' AND sender_name.inactive IS NULL)
          INNER JOIN auction_creatives AS subject
          ON (subject.id = CT.subject AND subject.type = 'subject' AND subject.inactive IS NULL)
          INNER JOIN auction_creatives AS description
          ON (description.id = CT.description AND description.type = 'description' AND description.inactive IS NULL)
          INNER JOIN auction_campaigns
          ON (auction_campaigns.id = CT.campaign_id AND auction_campaigns.status = 'active')
          GROUP BY campaign_id, domain, date, sender_name, subject, description, preheader
          ";
    $res = q($this->con, $q); 
    $init = ['sender_name', 'subject', 'description', 'preheader'];
    while($row = mysqli_fetch_assoc($res)) {
      // Campaigns
      $this->campaigns['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']][$row['date']] = $row;

      //Campaign Aggregate (for removing permutations based on aggregate)
      if(!isset($this->campaignsAgg['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']]))
        $this->campaignsAgg['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']] = ['opens' => 0, 'sends' => 0];
      
      $this->campaignsAgg['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']]['opens'] += $row['opens'];
      $this->campaignsAgg['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']]['sends'] += $row['sends'];
      
      foreach($init as $i) {
        // Aux
        $this->aux['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']][$i] = $row[$i];
        
         //Aggregate stats
        if(!isset($this->agg['creatives'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]))
          $this->agg['creatives'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']] = ['opens' => 0, 'sends' => 0];
        
        $this->agg['creatives'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]['opens'] += $row['opens'];
        $this->agg['creatives'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]['sends'] += $row['sends'];
        
        //Aggregate auxilliary
        $this->aggAux['creatives'][$row['domain']][$row['campaign_id']][$i][$row[$i]] = [];
        
        //Campaign aggregate type identification
        $this->campaignsAgg['creatives'][$row['domain']][$row['campaign_id']][$row['sender_name'].'_'.$row['subject'].'_'.$row['description'].'_'.$row['preheader']][$i] = $row[$i];
      }
    }
    
    //Get block stats
    $q = "SELECT SUM(sends) AS sends, 
                 SUM(opens) AS opens, 
                 SUM(clicks) AS clicks, 
                 SUM(commission) AS commission, 
                 SUM(unsubs) AS unsubs, 
                 SUM(spam_reps) AS spam_reps, 
                 SUM(own_spam_reps) AS own_spam_reps, 
                 view, greeting, CT.footer, spam, CT.campaign_id, date, domain
          FROM (
            SELECT COUNT(*) AS sends, 0 AS opens, 0 AS clicks, 0 AS commission, 0 AS unsubs, 0 AS spam_reps, 0 AS own_spam_reps, sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam
            
            UNION ALL
            
            SELECT 0, COUNT(*), 0, 0, 0, 0, 0, sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN (SELECT ref FROM opens GROUP BY ref) opens
            ON sends.ref = opens.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam
            
            UNION ALL
            
            SELECT 0, 0, COUNT(*), 0, 0, 0, 0, sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN (SELECT ref FROM clicks GROUP BY ref) clicks
            ON sends.ref = clicks.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam

            UNION ALL

            SELECT 0, 0, 0, SUM(amount), 0, 0, 0, sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM conversions
            INNER JOIN sends
            ON sends.ref = conversions.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE rejected IS NULL
            AND sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam
            
            UNION ALL

            SELECT 0, 0, 0, 0, COUNT(*), 0, 0, sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN subscribers
            ON sends.ref = subscribers.unsub_reason
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam

            UNION ALL

            SELECT 0, 0, 0, 0, 0, COUNT(*), 0, sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN spam_reports
            ON spam_reports.ref = sends.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam

            UNION ALL

            SELECT 0, 0, 0, 0, 0, 0, COUNT(*), sends.view, sends.greeting, sends.footer, sends.spam, campaign_id, DATE(sends.time) AS date, domain
            FROM sends
            INNER JOIN own_spam_reports
            ON own_spam_reports.ref = sends.ref
            INNER JOIN subscribers
            ON subscribers.email = sends.email AND subscribers.entity = sends.entity
            WHERE sends.time > '".$this->starting_date."'
            AND sends.time <= '".date("Y-m-d",strtotime('-1 day'))." 23:59:59'
            GROUP BY campaign_id, domain, date, view, greeting, footer, spam
          ) CT
          INNER JOIN auction_campaigns
          ON (auction_campaigns.id = CT.campaign_id AND auction_campaigns.status = 'active')
          GROUP BY campaign_id, domain, date, view, greeting, footer, spam
          ";
    $res = q($this->con, $q);
    $init = ['spam', 'view', 'greeting', 'footer'];
    while($row = mysqli_fetch_assoc($res)) {
      //Campaigns
      $this->campaigns['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']][$row['date']] = $row;
      //Campaign Aggregate (for removing permutations based on aggregate)
      if(!isset($this->campaignsAgg['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']]))
        $this->campaignsAgg['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']] = ['opens' => 0, 'sends' => 0];
      
      $this->campaignsAgg['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']]['opens'] += $row['opens'];
      $this->campaignsAgg['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']]['sends'] += $row['sends'];
      
      foreach($init as $i) {
        //Aux
        $this->aux['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']][$i] = $row[$i];

        //Aggregate stats
        if(!isset($this->agg['blocks'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]))
          $this->agg['blocks'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']] = ['opens' => 0, 'sends' => 0];
        
        $this->agg['blocks'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]['opens'] += $row['opens'];
        $this->agg['blocks'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]['sends'] += $row['sends'];
        
        //Aggregate auxilliary
        $this->aggAux['blocks'][$row['domain']][$row['campaign_id']][$i][$row[$i]] = [];
        
        //Campaign aggregate type identification
        $this->campaignsAgg['blocks'][$row['domain']][$row['campaign_id']][$row['spam'].'_'.$row['view'].'_'.$row['greeting'].'_'.$row['footer']][$i] = $row[$i];
      }
    }
    return true;
  }
  
  public function war() {    
    foreach($this->campaigns as $type => $type_data) {
      foreach($type_data as $domain => $campaigns) {
        // Loop through all campaigns
        foreach($campaigns as $campaign_id => $permutations) {
          // Loop through all permutations    
          foreach($permutations as $key1 => $permutation1) {
            foreach($permutations as $key2 => $permutation2) {
              // Intersect arrays
              $intersect1 = array_intersect_key($permutation1, $permutation2);
              $intersect2 = array_intersect_key($permutation2, $permutation1);

              // Sum over stats
              $stats1 = [
                'f' => 0,
                'n' => 0,
              ];        
              foreach($intersect1 as $element1) {
                $stats1['f'] += $element1['opens'];
                $stats1['n'] += $element1['sends'];
              }
              $stats2 = [
                'f' => 0,
                'n' => 0,
              ];        
              foreach($intersect2 as $element2) {
                $stats2['f'] += $element2['opens'];
                $stats2['n'] += $element2['sends'];
              } 
              
              if($stats1['n'] >= 30 && $stats2['n'] >= 30) {
                // Exclude autoresponders from instakill
                if(!in_array($campaign_id,$this->autoresponders)) {
                  $limit_check = Math::wilson($stats1['f'], $stats1['n'], 2.575);
                  if($limit_check->upper < $this->limits[$domain]) {
                    $this->aux[$type][$domain][$campaign_id][$key1]['instakill'] = $limit_check->upper;
                  }
                }
                $test = Math::Ztest($stats1, $stats2);
                if($test->likely_better_by >= 95) {
                  $this->aux[$type][$domain][$campaign_id][$key2]['losses'][$key1] = $test->likely_better_by;
                }
                $this->aux[$type][$domain][$campaign_id][$key1]['total'][$key2] = $test->likely_better_by;
              }
              $this->logger->debug('War; Type: '.$type.'; Domain: '.$domain.'; '.$key1.' vs '.$key2.'; Campaign: '.$campaign_id.';'.(isset($test->likely_better_by) ? ' Likely better by: '.$test->likely_better_by : ''), [$stats1,$stats2,$this->aux[$type][$domain][$campaign_id][$key1],$intersect1,$intersect2]);
            }
          }
        }
      }
    }
    return true;
  }
  
  public function warAgg() {    
    //$this->agg['blocks'][$row['domain']][$row['campaign_id']][$i][$row[$i]][$row['date']]['opens']
    foreach($this->agg as $type => $type_data) {
      foreach($type_data as $domain => $campaigns) {
        // Loop through all campaigns
        foreach($campaigns as $campaign_id => $element_types) {
          foreach($element_types as $element_type => $permutations) {
            // Loop through all permutations    
            foreach($permutations as $key1 => $permutation1) {
              foreach($permutations as $key2 => $permutation2) {
                // Intersect arrays
                $intersect1 = array_intersect_key($permutation1, $permutation2);
                $intersect2 = array_intersect_key($permutation2, $permutation1);

                // Sum over stats
                $stats1 = [
                  'f' => 0,
                  'n' => 0,
                ];        
                foreach($intersect1 as $element1) {
                  $stats1['f'] += $element1['opens'];
                  $stats1['n'] += $element1['sends'];
                }
                $stats2 = [
                  'f' => 0,
                  'n' => 0,
                ];        
                foreach($intersect2 as $element2) {
                  $stats2['f'] += $element2['opens'];
                  $stats2['n'] += $element2['sends'];
                } 

                if($stats1['n'] >= 100 && $stats2['n'] >= 100) {
                  if(!in_array($campaign_id,$this->autoresponders)) {
                    $limit_check = Math::wilson($stats1['f'], $stats1['n'], 2.575);
                    if($limit_check->upper < $this->limits[$domain]*0.5) {
                      $this->aggAux[$type][$domain][$campaign_id][$element_type][$key1]['instakill'] = $limit_check->upper;
                    }
                  }
                  $test = Math::Ztest($stats1, $stats2);
                  if($test->likely_better_by >= 99) {
                    $this->aggAux[$type][$domain][$campaign_id][$element_type][$key2]['losses'][$key1] = $test->likely_better_by;
                  }
                  $this->aggAux[$type][$domain][$campaign_id][$element_type][$key1]['total'][$key2] = $test->likely_better_by;
                }
                $this->logger->debug('WarAgg; Type: '.$type.'; Domain: '.$domain.'; Element: '.$element_type.'; '.$key1.' vs '.$key2.'; Campaign: '.$campaign_id.';'.(isset($test->likely_better_by) ? ' Likely better by: '.$test->likely_better_by : ''), [$stats1,$stats2,$this->aggAux[$type][$domain][$campaign_id][$element_type][$key1],$intersect1,$intersect2]);
              }
            }
          }
        }
      }
    }
    return true;
  }
  
  public function aftermathCreatives() {
    foreach($this->aux['creatives'] as $domain => $campaigns) {
      foreach($campaigns as $campaign_id => $permutations) {
        uasort($permutations, function($a, $b) {
            if(empty($a['total']) && empty($b['total']))
              return 0;
            if(empty($a['total']) && !empty($b['total']))
              return 1;
            if(!empty($a['total']) && empty($b['total']))
              return -1;

           return array_sum($a['total'])/count($a['total']) < array_sum($b['total'])/count($b['total']) ? 1 : -1;
        });
        $count = [
          'sender_name' => [],
          'subject' => [],
          'description' => []
        ];

        foreach($permutations as $key => $permutation) {
          if(!isset($this->creatives_whitelist[$domain][$campaign_id])) {
              $this->creatives_whitelist[$domain][$campaign_id] = [];
          }
          $pass = 0;
          if(!isset($permutation['instakill'])) {
            if(!in_array($permutation['sender_name'], $count['sender_name']) && count($count['sender_name']) < $this->required->sender_name) {
              $pass = 1;
              $count['sender_name'][] = $permutation['sender_name'];
            }
            if(!in_array($permutation['subject'], $count['subject']) && count($count['subject']) < $this->required->subject) {
              $pass = 1;
              $count['subject'][] = $permutation['subject'];
            }
            if(!in_array($permutation['description'],$count['description']) && count($count['description']) < $this->required->description) {
              $pass = 1;
              $count['description'][] = $permutation['description'];
            }
          }
          
          if($pass == 1) {
            $this->creatives_whitelist[$domain][$campaign_id][] = $permutation['sender_name'].'_'.$permutation['subject'].'_'.$permutation['description'].'_'.$permutation['preheader'];
          }
          
          if(($pass == 1 || !isset($permutation['losses']) || count($permutation['losses']) < 2) && !isset($permutations['instakill'])) {
              $this->logger->debug('Aftermath; Type: creatives; Domain: '.$domain.'; '.$key.'; Campaign: '.$campaign_id.'; Success, reason: '.($pass == 1 ? 'pass' : 'no losses'), $permutation);
              $q = "DELETE FROM forbidden_creative_permutations
                    WHERE campaign_id = ".(int)$campaign_id."
                    AND domain =  '".mysqli_real_escape_string($this->con, $domain)."'
                    AND sender_name = ".(int)$permutation['sender_name']."
                    AND subject = ".(int)$permutation['subject']."
                    AND description = ".(int)$permutation['description']."
                    AND preheader = ".(int)$permutation['preheader']."
                    LIMIT 10";
              q($this->con, $q);
          } else {
            $this->logger->debug('Aftermath; Type: creatives; Domain: '.$domain.'; '.$key.'; Campaign: '.$campaign_id.'; Fail, reason: '.(isset($permutations['instakill']) ? 'instakill' : 'losses'), $permutation);
            $q = "SELECT 1
                  FROM forbidden_creative_permutations
                  WHERE campaign_id = ".(int)$campaign_id."
                  AND domain =  '".mysqli_real_escape_string($this->con, $domain)."'
                  AND sender_name = ".(int)$permutation['sender_name']."
                  AND subject = ".(int)$permutation['subject']."
                  AND description = ".(int)$permutation['description']."
                  AND preheader = ".(int)$permutation['preheader']."
                  LIMIT 1";
            $res = q($this->con, $q);
            if(mysqli_num_rows($res) > 0)
              continue;

            $q = "INSERT INTO forbidden_creative_permutations (
                  campaign_id,
                  domain,
                  sender_name,
                  subject,
                  description,
                  preheader,
                  created_at,
                  forbid_ratio,
                  reason
                ) VALUES (
                  ".(int)$campaign_id.",
                  '".mysqli_real_escape_string($this->con, $domain)."',
                  ".(int)$permutation['sender_name'].",
                  ".(int)$permutation['subject'].",
                  ".(int)$permutation['description'].",
                  ".(int)$permutation['preheader'].",
                  NOW(),
                  1,
                  '".(isset($permutation['instakill']) ? 'instakill' : 'losses')."'
                )";
             q($this->con, $q);
          }
        }
      }
    }
  }
  
  public function aftermathBlocks() {
    foreach($this->aux['blocks'] as $domain => $campaigns) {
      foreach($campaigns as $campaign_id => $permutations) {
        foreach($permutations as $key => $permutation) {
          if((isset($permutation['losses']) && count($permutation['losses']) >= 2) || isset($permutation['instakill'])) {
            $this->logger->debug('Aftermath; Type: blocks; Domain: '.$domain.'; '.$key.'; Campaign: '.$campaign_id.'; fail, reason: '.(isset($permutations['instakill']) ? 'instakill' : 'losses'), $permutation);
            $q = "SELECT 1
                  FROM forbidden_block_permutations
                  WHERE campaign_id = ".(int)$campaign_id."
                  AND domain =  '".mysqli_real_escape_string($this->con, $domain)."'
                  AND spam = ".(int)$permutation['spam']."
                  AND view = ".(int)$permutation['view']."
                  AND greeting = ".(int)$permutation['greeting']."
                  AND footer = ".(int)$permutation['footer']."
                  LIMIT 1";
            $res = q($this->con, $q);
            if(mysqli_num_rows($res) > 0)
              continue;
                  
            $q = "INSERT INTO forbidden_block_permutations (
                    campaign_id,
                    domain,
                    spam,
                    view,
                    greeting,
                    footer,
                    created_at,
                    forbid_ratio,
                    reason
                  ) VALUES (
                    ".(int)$campaign_id.",
                    '".mysqli_real_escape_string($this->con, $domain)."',
                    ".(int)$permutation['spam'].",
                    ".(int)$permutation['view'].",
                    ".(int)$permutation['greeting'].",
                    ".(int)$permutation['footer'].",
                    NOW(),
                    1,
                    '".(isset($permutation['instakill']) ? 'instakill' : 'losses')."'
                  )";
            q($this->con, $q);
          } else {
            $this->logger->debug('Aftermath; Type: blocks; Domain: '.$domain.'; '.$key.'; Campaign: '.$campaign_id.'; success', $permutation);
            $q = "DELETE FROM forbidden_block_permutations
                  WHERE campaign_id = ".(int)$campaign_id."
                  AND domain =  '".mysqli_real_escape_string($this->con, $domain)."'
                  AND spam = ".(int)$permutation['spam']."
                  AND view = ".(int)$permutation['view']."
                  AND greeting = ".(int)$permutation['greeting']."
                  AND footer = ".(int)$permutation['footer']."
                  LIMIT 10";
            q($this->con, $q);
          }
        }
      }
    }
  }
                                            
  public function aftermathAggCreatives() {
    foreach($this->aggAux['creatives'] as $domain => $campaigns) {
      foreach($campaigns as $campaign_id => $element_types) {
        // Sort all combo permutations of campaign from worst => best based on wilson[upper]
        $all_permutations = $this->campaignsAgg['creatives'][$domain][$campaign_id];
        uasort($all_permutations, function($b, $a) {
          $wilson_a = Math::wilson($a['opens'],$a['sends'],2.575);
          $wilson_b = Math::wilson($b['opens'],$b['sends'],2.575);
          return  $wilson_b->upper < $wilson_a->upper ? 1 : -1;
        });
        foreach($element_types as $element_type => $permutations) {
          foreach($permutations as $key => $permutation) {
            // If single permutation has loss or instakill, kill bottom 60% of total combo permutations where combo includes single permutation id
            if(isset($permutation['losses']) || isset($permutation['instakill'])) {
              $c = 0;
              
              foreach($all_permutations as $combo_permutation_key => $combo_permutation) {
                if(in_array($combo_permutation_key, $this->creatives_whitelist[$domain][$campaign_id])) {
                  $pass = 1;
                } else {
                  $pass = 0;
                }
                
                if(count($all_permutations)*0.5 >= $c) {
                  $c++;
                  continue;
                }
                
                if($combo_permutation[$element_type] == $key) {
                  $this->logger->debug('AftermathAgg; Type: creatives; Domain: '.$domain.'; '.$combo_permutation_key.'; Campaign: '.$campaign_id.'; '.$key.'; test, reason: '.(isset($permutations['instakill']) ? 'instakill' : 'losses'), [$permutation, $combo_permutation]);

                  if($pass == 0 && $combo_permutation['sends'] >= $this->calculateSendReq(count($all_permutations))) {
                    $this->logger->debug('AftermathAgg; Type: creatives; Domain: '.$domain.'; '.$combo_permutation_key.'; Campaign: '.$campaign_id.'; fail, reason: '.(isset($permutations['instakill']) ? 'instakill' : 'losses'), [$permutation, $combo_permutation]);
                    $q = "SELECT 1
                          FROM forbidden_creative_permutations
                          WHERE campaign_id = ".(int)$campaign_id."
                          AND domain =  '".mysqli_real_escape_string($this->con, $domain)."'
                          AND sender_name = ".(int)$combo_permutation['sender_name']."
                          AND subject = ".(int)$combo_permutation['subject']."
                          AND description = ".(int)$combo_permutation['description']."
                          AND preheader = ".(int)$combo_permutation['preheader']."
                          LIMIT 1";
                    $res = q($this->con, $q);
                    if(mysqli_num_rows($res) > 0)
                      continue;

                    $q = "INSERT INTO forbidden_creative_permutations (
                            campaign_id,
                            domain,
                            sender_name,
                            subject,
                            description,
                            preheader,
                            created_at,
                            forbid_ratio,
                            reason
                          ) VALUES (
                            ".(int)$campaign_id.",
                            '".mysqli_real_escape_string($this->con, $domain)."',
                            ".(int)$combo_permutation['sender_name'].",
                            ".(int)$combo_permutation['subject'].",
                            ".(int)$combo_permutation['description'].",
                            ".(int)$combo_permutation['preheader'].",
                            NOW(),
                            1,
                            '".(isset($permutation['instakill']) ? 'instakill_aggregate' : 'losses_aggregate')."'
                          )";
                    q($this->con, $q);
                  } else {
                  $this->logger->debug('AftermathAgg; Type: creatives; Domain: '.$domain.'; '.$combo_permutation_key.'; Campaign: '.$campaign_id.'; '.$key.'; success, reason: '.(isset($permutations['instakill']) ? 'instakill' : 'losses'), [$pass, $permutation, $combo_permutation]);
                  }
                }
                $c++;
              }
            }
          }
        }
      }
    }
  }
  
  public function aftermathAggBlocks() {
    foreach($this->aggAux['blocks'] as $domain => $campaigns) {
      foreach($campaigns as $campaign_id => $element_types) {
        // Sort all combo permutations of campaign from worst => best based on wilson[upper]
        $all_permutations = $this->campaignsAgg['blocks'][$domain][$campaign_id];
        uasort($all_permutations, function($b, $a) {
          $wilson_a = Math::wilson($a['opens'],$a['sends'],2.575);
          $wilson_b = Math::wilson($b['opens'],$b['sends'],2.575);
          return  $wilson_b->upper < $wilson_a->upper ? 1 : -1;
        });
        foreach($element_types as $element_type => $permutations) {
          foreach($permutations as $key => $permutation) {
            // If single permutation has loss or instakill, kill bottom 60% of total combo permutations where combo includes single permutation id
            if(isset($permutation['losses']) || isset($permutation['instakill'])) {
              $c = 0;
              foreach($all_permutations as $combo_permutation_key => $combo_permutation) {

                if(count($all_permutations)*0.5 >= $c) {
                  $c++;
                  continue;
                }
                
                if($combo_permutation[$element_type] == $key && $combo_permutation['sends'] >= $this->calculateSendReq(count($all_permutations))) {
                  $this->logger->debug('AftermathAgg; Type: blocks; Domain: '.$domain.'; '.$combo_permutation_key.'; Campaign: '.$campaign_id.'; '.$key.'; fail, reason: '.(isset($permutations['instakill']) ? 'instakill' : 'losses'), [$permutation, $combo_permutation]);
                  $q = "SELECT 1
                        FROM forbidden_block_permutations
                        WHERE campaign_id = ".(int)$campaign_id."
                        AND domain =  '".mysqli_real_escape_string($this->con, $domain)."'
                        AND spam = ".(int)$combo_permutation['spam']."
                        AND view = ".(int)$combo_permutation['view']."
                        AND greeting = ".(int)$combo_permutation['greeting']."
                        AND footer = ".(int)$combo_permutation['footer']."
                        LIMIT 1";
                  $res = q($this->con, $q);
                  if(mysqli_num_rows($res) > 0)
                    continue;

                  $q = "INSERT INTO forbidden_block_permutations (
                          campaign_id,
                          domain,
                          spam,
                          view,
                          greeting,
                          footer,
                          created_at,
                          forbid_ratio,
                          reason
                        ) VALUES (
                          ".(int)$campaign_id.",
                          '".mysqli_real_escape_string($this->con, $domain)."',
                          ".(int)$combo_permutation['spam'].",
                          ".(int)$combo_permutation['view'].",
                          ".(int)$combo_permutation['greeting'].",
                          ".(int)$combo_permutation['footer'].",
                          NOW(),
                          1,
                          '".(isset($permutation['instakill']) ? 'instakill_aggregate' : 'losses_aggregate')."'
                        )";
                  q($this->con, $q);
                }
                $c++;
              }
            }
          }
        }
      }
    }
  }

  private function calculateSendReq($permutations) {
    $exp = 0.5;
    $const = 87;

    $send_requirement = (int)round($const/(pow($permutations,$exp)));

    return $send_requirement;
  }
}


