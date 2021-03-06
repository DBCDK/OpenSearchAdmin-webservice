<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright � 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/**
 * Objects are updated via the ES-database whereas 
 * relations are updated directly in Fedora
 */

require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/oci_class.php');
require_once('OLS_class_lib/es_database_class.php');

class openSearchAdmin extends webServiceServer {

  protected $curl;
  private $es_base;


  public function __construct(){
    webServiceServer::__construct('opensearchadmin.ini');

    if (!$timeout = $this->config->get_value('timeout', 'setup'))
      $timeout = 10;
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);
    $this->es_base = new es_database($this->config->get_section('es_database'));
  }



 /** \brief 
  *
  * errorType:
  * - authentication_error
  * - service_unavailable
  * - error_in_request
  * - operation_not_allowed_on_object
  * - relation_cannot_be_deleted
  */

 /** \brief createObject - Create object request. For creation of new data in the object respository.
  *
  * createObject-Dkabm: 
  *   ac:idenfier in dkabm_record is created/overwritten by agency:id from localIdentifier
  *
  * Request:
  * - localIdentifier
  * - dkabm-record
  * 
  * Response:
  * - status - values: object_created
  * - objectIdentifier
  */
  public function createObject($param) {
    $cor = &$ret->createObjectResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $cor->error->_value = 'authentication_error';
    else {
      if ($param->object->_value->theme) {  // this is obsolete
        if (!$this->is_local_identifier($param->object->_value->theme->_value->themeIdentifier->_value))
          $err = 'error_in_theme_identifier';
        elseif (!$this->empty_theme($param->object->_value->theme->_value->themeIdentifier->_value))
          $err = 'error_identifier_exists';
        else {
          $oid_value = &$param->object->_value->theme->_value->themeIdentifier->_value;
          $record->_namespace = $this->xmlns['oso'];
          $record->_value->type->_namespace = $this->xmlns['oso'];
          $record->_value->type->_value = 'theme';
          $record->_value->identifier = $this->make_identifier_obj($oid_value, 'oso');
          $record->_value->themeName->_namespace = $this->xmlns['oso'];
          $record->_value->themeName->_value = $param->object->_value->theme->_value->themeName->_value;
          $ting->container->_namespace = $this->xmlns['ting'];
          $ting->container->_value->object = &$record;
          $xml = $this->objconvert->obj2xmlNS($ting);
  
          $agency = $this->get_agency($oid_value);
          $rec_format = 'theme';
        }
      } else {
        if (!$this->is_local_identifier($param->localIdentifier->_value)) {
          $err = 'error_in_local_identifier';
        } elseif (!$this->empty_dkabm($param->localIdentifier->_value)) {
          $err = 'error_identifier_exists';
        } else {
          $oid_value = &$param->localIdentifier->_value;
          $ting->container->_value->record = &$param->object->_value->record;
          $ting->container->_namespace = $this->xmlns['ting'];
          if ($this->validate['dkabm']) {
            $val->record = &$param->object->_value->record;
            $xml = $this->objconvert->obj2xmlNS($val);
            unset($val);
            if (!$this->validate_xml($xml, $this->validate['dkabm']))
              $err = 'error_validating_record';
          }

          if ($param->object->_value->article) {
            $ting->container->_value->article = &$param->object->_value->article;
            $ting->container->_value->article->_namespace = $this->xmlns['docbook'];
            if ($this->validate['docbook']) {
              $val->article = &$param->object->_value->article;
              $xml = $this->objconvert->obj2xmlNS($val);
              unset($val);
              if (!$this->validate_xml($xml, $this->validate['docbook']))
                $err = 'error_validating_record';
            }
          }

    // set oso-identifier
          if (empty($err)) {
            $ting->container->_value->object->_namespace = $this->xmlns['oso'];
            $ting->container->_value->object->_value->identifier = $this->make_identifier_obj($oid_value, 'oso');
            $xml = $this->objconvert->obj2xmlNS($ting);
            $agency = $this->get_agency($oid_value);
            $rec_format = 'dkabm';
          } 
        }
      }
//var_dump($param); echo($err); echo($xml); var_dump($cor); var_dump($agency); die();
      if (empty($err)) {
        try {
          $this->es_base->ship_to_ES($xml, $agency, $rec_format);
        } catch (esException $e) {
          $err = $e->getMessage();
        }
      }
      if ($err)
        $cor->error->_value = $err;
      else {
        verbose::log(TRACE, "createObject:: agency: $agency xml: " . $xml);
        $cor->status->_value = 'object_created';
        $cor->objectIdentifier->_value = $oid_value;
      }
    }
    //var_dump($param); die();
    return $ret;
  }


 /** \brief copyObject - Copy object request. 
  * Creates a copy of the object in the object repository and adds it to submitters collection
  *
  * Request:
  * - localIdentifier
  * - objectIdentifier
  * 
  * Response:
  * - status - values: object_copied
  * - status
  * - objectIdentifier
  *
  */
  public function copyObject($param) {
    $cor = &$ret->copyObjectResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $cor->error->_value = 'authentication_error';
    else {
      if (!$this->is_local_identifier($param->localIdentifier->_value))
        $cor->error->_value = 'error_in_local_identifier';
      elseif (!$this->is_identifier($param->objectIdentifier->_value))
        $cor->error->_value = 'error_in_object_identifier';
      elseif (!($copyobj = $this->object_get($param->objectIdentifier->_value)))
        $cor->error->_value = 'error_fetching_object_record';
      else {
        $xmlobj = $this->xmlconvert->soap2obj($copyobj);
        if ($xmlobj->container->_value->record) {				// dkabm record
          if (!$this->empty_dkabm($param->localIdentifier->_value))
            $cor->error->_value = 'error_identifier_exists';
          elseif ($this->empty_dkabm($param->objectIdentifier->_value))
            $cor->error->_value = 'error_fetching_object_record';
          else {
            $ting->container->_value->object->_namespace = $this->xmlns['oso'];
            $ting->container->_value->object->_value->identifier = $this->make_identifier_obj($param->localIdentifier->_value, 'oso');
            $ting->container->_value->record = &$xmlobj->container->_value->record;
            $ting->container->_namespace = $this->xmlns['ting'];
            $xml = $this->objconvert->obj2xmlNS($ting);
            $rec_format = 'dkabm';
          }
        } elseif ($xmlobj->container->_value->object) {				// theme object
          if (!$this->empty_theme($param->localIdentifier->_value))
            $cor->error->_value = 'error_identifier_exists';
          elseif ($this->empty_theme($param->objectIdentifier->_value))
            $cor->error->_value = 'error_fetching_object_record';
          else {
            $ting->container->_value->object = &$xmlobj->container->_value->object;
            $ting->container->_namespace = $this->xmlns['ting'];
            $ting->container->_value->object->_namespace = $this->xmlns['oso'];
            $ting->container->_value->object->_value->identifier = $this->make_identifier_obj($param->localIdentifier->_value, 'oso');
            $xml = $this->objconvert->obj2xmlNS($ting);
            $rec_format = 'theme';
          }
        } else
          $cor->error->_value = 'no_record_in_object';
        if ($xml) {
          $agency = $this->get_agency($param->localIdentifier->_value);
          try {
            $this->es_base->ship_to_ES($xml, $agency, $rec_format);
          } catch (esException $e) {
            $err = $e->getMessage();
          }
          if ($err)
            $cor->error->_value = $err;
          else {
            verbose::log(TRACE, "copyObject:: agency: $agency xml: " . $xml);
            $cor->status->_value = 'object_copied';
            $cor->objectIdentifier->_value = $param->localIdentifier->_value;
          }
        }
      }
    }
    //var_dump($ret); var_dump($param); die();
    return $ret;
  }


 /** \brief updateObject - Update object request. For updating data in the object respository. 
  * Creates a copy of the object in the object repository with local changes, and adds it to 
  * submitters collection.
  *
  * Request:
  * - localIdentifier
  * - objectIdentifier
  * - record
  * 
  * Response:
  * - status - values: object_updated
  * - objectIdentifier
  *
  */
  public function updateObject($param) {
    $uor = &$ret->updateObjectResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $uor->error->_value = 'authentication_error';
    else {
      if ($param->object->_value->theme) {  // this is obsolete
        if (!$this->is_local_identifier($param->object->_value->theme->_value->themeIdentifier->_value))
          $err = 'error_in_theme_identifier';
        elseif (!$this->object_exists($param->object->_value->theme->_value->themeIdentifier->_value))
          $err = 'error_fetching_theme_record';
        else {
          $record->_namespace = $this->xmlns['oso'];
          $record->_value->type->_namespace = $this->xmlns['oso'];
          $record->_value->type->_value = 'theme';
          $record->_value->identifier = $this->make_identifier_obj($param->object->_value->theme->_value->themeIdentifier->_value, 'oso');
          $record->_value->themeName->_namespace = $this->xmlns['oso'];
          $record->_value->themeName->_value = $param->object->_value->theme->_value->themeName->_value;
          $ting->container->_namespace = $this->xmlns['ting'];
          $ting->container->_value->object = &$record;
          $xml = $this->objconvert->obj2xmlNS($ting);
          $agency = $this->get_agency($param->object->_value->theme->_value->themeIdentifier->_value);
          $rec_format = 'theme';
        }
      } else {
        if (!$this->is_local_identifier($param->objectIdentifier->_value))
          $err = 'error_in_object_identifier';
        elseif (!$this->object_exists($param->objectIdentifier->_value))
          $err = 'error_fetching_object_record';
        else {
          $ting->container->_value->record = &$param->object->_value->record;
          $ting->container->_namespace = $this->xmlns['ting'];
          if ($this->validate['dkabm']) {
            $val->record = &$param->object->_value->record;
            $xml = $this->objconvert->obj2xmlNS($val);
            unset($val);
            if (!$this->validate_xml($xml, $this->validate['dkabm']))
              $err = 'error_validating_record';
          }
          if ($param->object->_value->article) {
            $ting->container->_value->article = &$param->object->_value->article;
            $ting->container->_value->article->_namespace = $this->xmlns['docbook'];
            if ($this->validate['docbook']) {
              $val->article = &$param->object->_value->article;
              $xml = $this->objconvert->obj2xmlNS($val);
              unset($val);
              if (!$this->validate_xml($xml, $this->validate['docbook']))
                $err = 'error_validating_record';
            }
          }
  // make/change ac-identifier to new one
          if (empty($err)) {
            $ting->container->_value->object->_namespace = $this->xmlns['oso'];
            $ting->container->_value->object->_value->identifier = $this->make_identifier_obj($param->objectIdentifier->_value, 'oso');
            $agency = $this->get_agency($param->objectIdentifier->_value);
            $rec_format = 'dkabm';
            $xml = $this->objconvert->obj2xmlNS($ting);
          } 
        }
      }
      if (empty($err)) {
        try {
          $this->es_base->ship_to_ES($xml, $agency, $rec_format);
        } catch (esException $e) {
          $err = $e->getMessage();
        }
      }
      if ($err)
        $uor->error->_value = $err;
      else {
        verbose::log(TRACE, "updateObject:: agency: $agency xml: " . $xml);
        $uor->status->_value = 'object_updated';
        $uor->objectIdentifier->_value = $param->localIdentifier->_value;
      }
    }
    //var_dump($param); die();
    return $ret;
  }



 /** \brief deleteObject - Delete object request - make an empty record
  *
  * Request:
  * - objectIdentifier
  * 
  * Response:
  * - status - values: object_deleted
  *
  */
  public function deleteObject($param) {
    $dor = &$ret->deleteObjectResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $dor->error->_value = 'authentication_error';
    else {
      if (!$this->is_identifier($param->objectIdentifier->_value))
        $dor->error->_value = 'error_in_object_identifier';
      elseif ($this->object_exists($param->objectIdentifier->_value)) {
        $delete_record->_namespace = $this->xmlns['dkabm'];
        $delete_record->_value->identifier = $this->make_identifier_obj($param->objectIdentifier->_value, 'ac');
        $origdata->_namespace = $this->xmlns['ting'];
        $origdata->_value->status->_value = 'd';
        $ting->container->_namespace = $this->xmlns['ting'];
        $ting->container->_value->record = &$delete_record;
        $ting->container->_value->originalData = &$origdata;
        $xml = $this->objconvert->obj2xmlNS($ting);
        $agency = $this->get_agency($param->objectIdentifier->_value);
        $rec_format = 'dkabm';
        try {
          $this->es_base->ship_to_ES($xml, $agency, $rec_format);
        } catch (esException $e) {
          $err = $e->getMessage();
        }
        if ($err)
          $dor->error->_value = $err;
        else {
          verbose::log(TRACE, "deleteObject:: agency: $agency xml: " . $xml);
          $dor->status->_value = 'object_deleted';
        }
      } else
        $dor->error->_value = 'error_fetching_object_record';
    }
    //var_dump($ret); var_dump($param); die();
    return $ret;
  }



 /** \brief createRelation
  *
  * Fedoraparms:
  * - String pid The PID of the object. 
  * - String relationship The predicate. 
  * - String object The object (target). 
  * - boolean isLiteral A boolean value indicating whether the object is a literal. Set to true
  * - String datatype The datatype of the literal. Optional. Set to null
  *
  * Request:
  * - relationSubject
  * - relation - values: rev:hasReview 
  *                      fedora:isMemberOfCollection 
  *                      oss:isMemberOfTheme 
  *                      oss:hasCover 
  *                      isMemberOfWork
  * - relationObject
  * 
  * Response:
  * - status - values: relation_created
  *
  */
  public function obsolete_createRelation($param) {
    $crr = &$ret->createRelationResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $crr->error->_value = 'authentication_error';
    else {
      if (($from = $param->relationSubject->_value) &&
          ($rel = $param->relation->_value) &&
          ($to = $param->relationObject->_value)) {
        $relations = $this->config->get_value('relation', 'setup');
        if (!$this->object_exists($from))
          $crr->error->_value = 'unknown_relationSubject';
        elseif (!$this->object_exists($to))
          $crr->error->_value = 'unknown_relationObject';
        elseif (!isset($relations[$rel]))
          $crr->error->_value = 'unknown_relation';
        elseif ($err = $this->create_relation($from, $to, $rel))
          $crr->error->_value = $err;
        elseif (($rev = $relations[$rel]) && ($err = $this->create_relation($to, $from, $rev))) {
          // $this->delete_relation($from, $to, $rel);
          $crr->error->_value = $err;
        } else
          $crr->status->_value = 'relation_created';
  //var_dump($result);
  //var_dump($this->curl->get_status());
  //var_dump(html_entity_decode($fed_req));
  //var_dump($this->curl->get_status());
      } else 
        $crr->error->_value = 'error_in_request';
    }
//var_dump($param);
//var_dump($ret); die();
    // verbose::log(TIMER, 'createRelation:: ' . $this->watch->dump());
    return $ret;
  }


 /** \brief deleteRelation
  *
  * Fedoraparms:
  * - String pid The PID of the object. 
  * - String relationship The predicate. 
  * - String object The object (target). 
  * - boolean isLiteral A boolean value indicating whether the object is a literal. Set to true
  * - String datatype The datatype of the literal. Optional. Set to null
  *
  * Request:
  * - relationSubject
  * - relation - values: see createRelation above
  * - relationObject
  * 
  * Response:
  * - status - values: relation_deleted
  *
  */
  public function obsolete_deleteRelation($param) {
    $drr = &$ret->deleteRelationResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $drr->error->_value = 'authentication_error';
    else {
      if (($from = $param->relationSubject->_value) &&
          ($rel = $param->relation->_value) &&
          ($to = $param->relationObject->_value)) {
        $relations = $this->config->get_value('relation', 'setup');
        if (!$this->object_exists($from))
          $drr->error->_value = 'unknown_relationSubject';
        elseif (!$this->object_exists($to))
          $drr->error->_value = 'unknown_relationObject';
        elseif (!isset($relations[$rel]))
          $drr->error->_value = 'unknown_relation';
        elseif (!$this->relation_exists($from, $to, $rel))
          $drr->error->_value = 'relation_not_found';
        elseif ($err = $this->delete_relation($from, $to, $rel))
          $drr->error->_value = $err;
        elseif (($rev = $relations[$rel]) && ($err = $this->delete_relation($to, $from, $rev)))
          $drr->error->_value = $err;
        else
          $drr->status->_value = 'relation_deleted';
//var_dump($result);
//var_dump($this->curl->get_status());
//var_dump(html_entity_decode($fed_req));
//var_dump($this->curl->get_status());
      } else 
        $drr->error->_value = 'error_in_request';
    }
//var_dump($param);
//var_dump($ret); die();
    // verbose::log(TIMER, 'deleteRelation:: ' . $this->watch->dump());
    return $ret;
  }


 /** \brief getTaskStatus
  *
  * Request:
  * - String taskId task-package-identifier
  *
  * Response:
  * - taskStatus - taskStatusType
  *
  * @todo This method is not yet fully implemented
  */
  public function getTaskStatus($param) {
    $gtpr = &$ret->getTaskStatusResponse->_value;
    if (!$this->aaa->has_right('opensearchadmin', 500))
      $drr->error->_value = 'authentication_error';
    else {
      // Todo: Check the implementation of the next method
      $status = $this->es_base->get_task_status($param->taskId->_value);
      $status = 'The getTaskStatus method is not yet implemented';
    }
    $gtpr->taskStatus->_value = $status;
//var_dump($param);
//print_r($ret); die();
    return $ret;
  }

  /**************** private functions ****************/


 /** \brief create relation 
  */
// come �a : http://oss.dbc.dk/rdf/dkbib#aakb_catalog
  private function create_relation($from, $to, $rel) {
    list($prefix, $val) = explode(':', $rel);
    if ($prefix && $val && $this->xmlns[$prefix])
      $rel = $this->xmlns[$prefix] . '#' . $val;
    $fed_req = sprintf($this->config->get_value('xml_create_relation'), $from, $rel, $to);
    $this->curl->set_soap_action('createRelation');
    $this->curl->set_post_xml(html_entity_decode($fed_req));
    $this->curl->set_authentication($this->config->get_value('fedora_user'), 
                                    $this->config->get_value('fedora_passwd'));
    $result = $this->curl->get($this->config->get_value('fedora_API_M', 'setup'));
    if (!$result || $this->curl->get_status('http_code') >= 300)
      return 'error_reaching_fedora';

    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML($result)) 
      return 'error_parsing_fedora_result';

    if ($dom->getElementsByTagName('added')->item(0)->nodeValue <> 'true')
      return 'relation_cannot_be_created';
  }


 /** \brief delete relation 
  */
  private function delete_relation($from, $to, $rel) {
    list($prefix, $val) = explode(':', $rel);
    if ($prefix && $val && $this->xmlns[$prefix])
      $rel = $this->xmlns[$prefix] . '#' . $val;
    $fed_req = sprintf($this->config->get_value('xml_delete_relation'), $from, $rel, $to);
    $this->curl->set_soap_action('purgeRelation');
    $this->curl->set_post_xml(html_entity_decode($fed_req));
    $this->curl->set_authentication($this->config->get_value('fedora_user'), 
                                    $this->config->get_value('fedora_passwd'));
    $result = $this->curl->get($this->config->get_value('fedora_API_M', 'setup'));
    if (!$result || $this->curl->get_status('http_code') >= 300)
      return 'error_reaching_fedora';

    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML($result)) 
      return 'error_parsing_fedora_result';

    if ($dom->getElementsByTagName('purged')->item(0)->nodeValue <> 'true')
      return 'relation_cannot_be_deleted';
  }


 /** \brief Correct or create record identifier
  */
  private function set_record_identifier(&$id_obj, $local_id) {
    if (empty($id_obj))
      $id_obj = array();
    elseif (!is_array($id_obj) && !empty($id_obj))
      $id_obj = array($id_obj);
    $ac_id = $this->make_identifier_obj($local_id, 'ac');
    foreach ($id_obj as $key => $id)
      if ($id->_namespace == $this->xmlns['ac']) {
        $id_obj[$key]->_value = $ac_id->_value;
        return;
      }
    $id_obj[] = $ac_id;
  }


 /** \brief 
  */
  private function make_identifier_obj($local_id, $ns_pref = '') {
    list($agency, $rec_id) = explode(':', $local_id);
    $identifier->_namespace = empty($ns_pref) ? $this->xmlns['ac'] : $this->xmlns[$ns_pref];
    $identifier->_value = $rec_id . '|' . $agency;
    return $identifier;
  }


 /** \brief 
  */
  private function get_agency($local_id) {
    list($agency, $rec_id) = explode(':', $local_id);
    return $agency;
  }


 /** \brief 
  */
  private function get_identifier($local_id) {
    list($agency, $rec_id) = explode(':', $local_id);
    return $rec_id;
  }


 /** \brief 
  */
  private function relation_exists($from, $to, $rel) {
    //$this->set_curl();
    $rels_ext = $this->curl->get(sprintf($this->config->get_value('fedora_get_rels_ext'), $from));
    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML($rels_ext)) 
      return FALSE;
    else {
      if (strpos($rel, ':'))
        list($rel_ns, $rel) = explode(':', $rel);
//var_dump($rel);
//var_dump($dom->getElementsByTagName($rel)->item(0)->nodeValue);
//print_r($rels_ext); die();
      return ($dom->getElementsByTagName($rel)->item(0)->nodeValue == $to);
    }
  }


 /** \brief 
  */
  private function is_local_identifier($id) {
    list($agency, $rec_id) = explode(':', $id);
    return (!empty($agency) && !empty($rec_id));
    //return ((strlen($agency) == 6) && is_numeric($agency) && is_numeric($rec_id));
  }


 /** \brief 
  */
  private function is_identifier($id) {
    list($agency, $rec_id) = explode(':', $id);
    return (!empty($agency) && !empty($rec_id));
    //return (!empty($agency) && is_numeric($rec_id));
  }


 /** \brief 
  */
  private function object_get($obj_id) {
    $f_req = sprintf($this->config->get_value('fedora_get_raw'), $obj_id);
    $obj = $this->curl->get($f_req);
    if ($obj && $this->curl->get_status('http_code') < 300) 
      return $obj;
    else
      return FALSE;
  }


 /** \brief 
  */
  private function object_exists($obj_id) {
    $f_req = sprintf($this->config->get_value('fedora_get'), $obj_id);
    if ($this->curl->get($f_req))
      return $this->curl->get_status('http_code') < 300;
    else
      return FALSE;
  }


 /** \brief 
  */
  private function empty_theme($theme_id) {
    return $this->empty_obj($theme_id, 'object', 'identifier', $this->xmlns['oso']);
  }

 /** \brief 
  */
  private function empty_dkabm($dkabm_id) {
    return $this->empty_obj($dkabm_id, 'record', 'identifier', $this->xmlns['ac']);
  }

 /** \brief empty_obj Deleted records contain only one field - check for this
  *
  * @param id Fedora-pid of object (string)
  * @param rec_type object or record (string)
  * @param id_name name of identifier tag (string)
  * @param id_namespace of identifier tag (string)
  *
  * $return (boolean)
  * 
  * Returns
  */
  private function empty_obj($id, $rec_type, $id_name, $id_namespace) {
    if ($obj = $this->object_get($id)) {
      $xmlobj = $this->xmlconvert->soap2obj($obj);
      if ($xmlobj->container->_value)
        foreach ($xmlobj->container->_value as $type => $rec) {
          if ($type <> $rec_type)
            return FALSE;
          foreach ($rec->_value as $tag => $fld)
            if ($tag <> $id_name || $fld->_namespace <> $id_namespace)
              return FALSE;
        }
    }

    return TRUE;
  }

 /** \brief 
  */
  private function url_decode_array($arr) {
    $ret = '';
    if (is_array($arr))
      foreach ($arr as $key => $val)
        $ret[urldecode($key)] = urldecode($val);
    return $ret;
  }

  /** \brief Echos config-settings
   *
   */
  public function show_info() {
    echo '<pre>';
    echo 'version              ' . $this->config->get_value('version', 'setup') . '<br/>';
    echo 'logfile              ' . $this->config->get_value('logfile', 'setup') . '<br/>';
    echo 'verbose              ' . $this->config->get_value('verbose', 'setup') . '<br/>';
    echo 'fedora_get           ' . $this->config->get_value('fedora_get', 'setup') . '<br/>';
    echo 'fedora_get_rels_ext  ' . $this->config->get_value('fedora_get_rels_ext', 'setup') . '<br/>';
    echo 'fedora_get_raw       ' . $this->config->get_value('fedora_get_raw', 'setup') . '<br/>';
    echo 'fedora_API_M         ' . $this->config->get_value('fedora_API_M', 'setup') . '<br/>';
    echo 'es_credentials       ' . $this->strip_oci_pwd($this->config->get_value('es_credentials', 'setup')) . '<br/>';
    echo 'aaa_credentials      ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
    echo '</pre>';
    die();
  }

  private function strip_oci_pwd($cred) {
    if (($p1 = strpos($cred, '/')) && ($p2 = strpos($cred, '@')))
      return substr($cred, 0, $p1) . '/********' . substr($cred, $p2);
    else
      return $cred;
  }


}
/*
 * MAIN
 */

$ws=new openSearchAdmin();
$ws->handle_request();

?>
