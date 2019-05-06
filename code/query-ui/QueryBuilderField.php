<?php


/**
 * An advanced form field used for editing and saving queries
 *
 * @author Craig Weber <craig@crgwbr.com>
 * @copyright (c) 2013 Craig Weber <craig@crgwbr.com>
 * @package silverstripe-views
 * @subpackage query-ui
 */
class QueryBuilderField extends FormField {

   protected $readonly = false;
   protected $disabled = false;
   private $resultsRetriever = null;
   private $allowExport = true;


   /**
    * array_merge_recursive does indeed merge arrays, but it converts values with duplicate
    * keys to arrays rather than overwriting the value in the first array with the duplicate
    * value in the second array, as array_merge does. I.e., with array_merge_recursive,
    * this happens (documented behavior):
    *
    * array_merge_recursive(array('key' => 'org value'), array('key' => 'new value'));
    *     => array('key' => array('org value', 'new value'));
    *
    * array_merge_recursive_distinct does not change the datatypes of the values in the arrays.
    * Matching keys' values in the second array overwrite those in the first array, as is the
    * case with array_merge, i.e.:
    *
    * array_merge_recursive_distinct(array('key' => 'org value'), array('key' => 'new value'));
    *     => array('key' => array('new value'));
    *
    * Parameters are passed by reference, though only for performance reasons. They're not
    * altered by this function.
    *
    * @param array $array1
    * @param array $array2
    * @return array
    * @author Daniel <daniel (at) danielsmedegaardbuus (dot) dk>
    * @author Gabriel Sobrinho <gabriel (dot) sobrinho (at) gmail (dot) com>
    * @see http://www.php.net/manual/en/function.array-merge-recursive.php
    */
   public static function array_merge_recursive_distinct(array &$array1, array &$array2){
      $merged = $array1;

      foreach ($array2 as $key => &$value) {
         if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
            $merged[$key] = self::array_merge_recursive_distinct($merged[$key], $value);
         } else {
            $merged[$key] = $value;
         }
      }

      return $merged;
   }


   /**
    * Builds an array of metadata about our core DataObject classes
    * and their subclasses.
    *
    * @return array
    */
   private static function build_core_type_structures() {
      // Create lambda function wrapper so that it can be passed as a parameter
      $buildStructure = function($cls, $base) {
         return QueryBuilderField::build_data_type_structure($cls, $base);
      };

      // Loop through the 4 main base data classes
      $structure = array();
      foreach(self::list_root_data_classes() as $base) {
         // Loop through all subclasses of the main base data types
         $subclasses = array_values(ClassInfo::subclassesFor($base));
         foreach ($subclasses as $cls) {
            $structure[$cls] = self::map_ancestry($cls, $buildStructure);

            // Allow each class a hook for augmenting types as needed
            // Trust that no one uses this for evil
            if (is_callable("{$cls}::augment_types"))
               $cls::augment_types($structure);
         }
      }

      return $structure;
   }


   /**
    * Build a metadata structure about a single class.
    *
    * @param class $cls Class to Describe
    * @param class $base Parent Class
    * @return array
    */
   public static function build_data_type_structure($cls, $base) {
      $structure = array(
         'base' => $base,
         'fields' => array());

      // List Database Fields
      $fields = self::get_class_fields($cls);

      foreach($fields as $name => $type) {
         $structure['fields'][$name] = self::get_input_type($cls, $name, $type);
      }

      return $structure;
   }


   /**
    * Returns an array of fields and relationships for the given class.
    * The fieldType may be either a DB Column type or a Class (DataObject)
    * type.
    *
    * @param class $cls
    * @return array('NameOfField' => 'FieldType')
    */
   public static function get_class_fields($cls) {
      $fields = QueryBuilderField::get_database_property($cls, 'db');

      // Describe Database Fields
      if (!empty($fields)) {
         $description = DB::query("DESCRIBE {$cls}");

         foreach ($description as $column) {
            if (array_key_exists($column['Field'], $fields)) {
               $fields[$column['Field']] = $column['Type'];
            }
         }
      }

      $fields = array_merge($fields, QueryBuilderField::get_database_property($cls, 'has_one'));
      $fields = array_merge($fields, QueryBuilderField::get_database_property($cls, 'has_many'));

      return $fields;
   }


   /**
    * Given a class, return the fields matching the kind of relationship provided.
    *
    * @param class $cls DataObject Class
    * @param string $prop [db, has_one, has_many]
    * @return array('NameOfField' => 'FieldType')
    */
   public static function get_database_property($cls, $prop) {
      if ($prop == 'has_one' && !QueryBuilderField::traverse_has_one_relationship($cls)) {
         return array();
      }

      if ($prop == 'has_many' && !QueryBuilderField::traverse_has_many_relationship($cls)) {
         return array();
      }

      return Config::inst()->get($cls, $prop, Config::UNINHERITED) ?: array();
   }


   /**
    * Return an array of metadata describing a DataObject field. This can be
    * overwritten in any DataObject with Field granularity by creating
    * methods named Class::get_<lowercasefieldname>_input_type()
    *
    * @param class $cls DataObject Class
    * @param string $name Name of Field
    * @param string $dbType Type of Field
    * @return array
    */
   public static function get_input_type($cls, $name, $dbType) {
      // Let individual classes override this as needed
      $fnName = "get_" . strtolower($name) . "_input_type";
      if (is_callable("{$cls}::{$fnName}")) {
         return $cls::$fnName();
      }

      $type = '';
      $defaults = Config::inst()->get($cls, 'defaults');
      $default = isset($defaults[$name]) ? $defaults[$name] : '';
      $options = array();

      switch (true) {
         case (preg_match('/BOOL|TINYINT/i', $dbType)):
            $type = 'bool';
            break;

         case (preg_match('/INT/i', $dbType)):
            $type = 'int';
            break;

         case (preg_match('/ENUM\(((\'([A-Za-z]+)\',?)+)\)/i', $dbType, $match)):
            $type = 'select';
            $options = $match[1];
            $options = explode(',', $options);
            foreach ($options as &$option) {
               $option = trim($option, "'");
            }
            $default = reset($options);
            break;

         case class_exists($dbType):
            $hasOne = array_keys(self::get_database_property($cls, 'has_one'));
            $type = in_array($name, $hasOne)
               ? 'has_one'
               : 'has_many';
            $options = array_values(ClassInfo::subclassesFor($dbType));
            break;

         case (preg_match('/VARCHAR/i', $dbType)):
         default:
            $type = 'text';
            break;
      }

      // Convert to an associative array
      $options = empty($options) ? null : array_combine($options, $options);

      return array(
         'type' => $type,
         'default' => $default,
         'options' => $options);
   }


   /**
    * Convenience function for getting a value from an array where
    * the key might not exist.
    *
    * @param array $arr
    * @param string $key
    * @param mixed $default Optional. Defaults to null
    * @return mixed
    */
   public static function get_value($arr, $key, $default = null) {
      return array_key_exists($key, $arr) ? $arr[$key] : $default;
   }


   /**
    * Returns an array of our core DataObjects base classes. All other
    * view-related DataObject's inherit from these classes.
    */
   private static function list_root_data_classes() {
      $baseClasses = array(
         'ViewResultsRetriever',
         'ViewResultsSorter',
         'QuerySort',
         'QueryPredicate',
         'PredicateCondition',
         'FieldPredicateValue');
      return $baseClasses;
   }


   /**
    * Iterate over the provided class and it's inheritance
    * ancestry, applying the provided function to each. Iterates
    * from the base class towards the (given) top level class. Merges
    * function output into a single array and returns it.
    *
    * @param class $cls The name of a subclass of DataObject
    * @param function $fn($className, $immediateParentClassName) A function to apply to the ancestry of $cls
    * @return array
    */
   private static function map_ancestry($cls, $fn) {
      $base = ClassInfo::baseDataClass($cls);
      if (!$base)
         return array();

      $classHierarchy = array();
      while ($parents = ClassInfo::ancestry($cls)) {
         $classHierarchy[] = $cls;

         // Stop if we've reach the base class
         if ($cls == $base)
            break;

         // Pop the stack so that we traverse up the
         // class hierarchy by one step
         array_pop($parents);
         $cls = array_pop($parents);
      }

      // Iterate over entire hierarchy from Base -> Top
      $mergedOutput = array();
      $immediateParent = count($classHierarchy) > 1 ? $classHierarchy[1] : null;
      $classHierarchy = array_reverse($classHierarchy);
      foreach ($classHierarchy as $cls) {
         $partialOutput = $fn($cls, $immediateParent);

         if (is_array($partialOutput))
            $mergedOutput = self::array_merge_recursive_distinct($mergedOutput, $partialOutput);
         else
            $mergedOutput[] = $partialOutput;
      }

      return $mergedOutput;
   }


   /**
    * Accept a data representation. Recursively instantiate and save
    * data objects to match it. Inverse of {@link QueryBuilderField::buildQueryRepr()}
    *
    * @param string JSON Data Object Representation
    * @return DataObject
    */
   public static function save($structure) {
      $structure = json_decode($structure, true);
      $structure = $structure['data'];

      return self::save_object($structure);
   }


   /**
    * Accept a data representation. Recursively instantiate and save
    * data objects to match it. Used by {@link QueryBuilderField::save()}
    *
    * @param array Data Object Representation
    * @return DataObject
    */
   public static function save_object($structure) {
      if (empty($structure))
         return;

      $modelClass = $structure['type'];
      $fields = $structure['fields'];
      $typeInfo = self::build_core_type_structures();

      $validTypes = array_keys($typeInfo);
      if (!in_array($modelClass, $validTypes))
         return;

      $obj = new $modelClass();
      $obj->write();

      $saveChildObject = function($property, $childStructure) use (&$obj) {
         $fnName = "resolve{$property}Structure";
         if (method_exists($obj, $fnName))
            return $obj->$fnName($childStructure);

         return QueryBuilderField::save_object($childStructure);
      };

      $setProperties = function($cls, $parent) use (&$obj, &$fields, &$saveChildObject) {
         foreach (QueryBuilderField::get_database_property($cls, 'db') as $property => $type) {
            $value = QueryBuilderField::get_value($fields, $property, null);

            switch(true) {
               case strtolower((string)$value) == 'true':
                  $value = 1;
                  break;
               case strtolower((string)$value) == 'false':
                  $value = 0;
                  break;
               case is_numeric($value):
                  $value = (int)$value;
                  break;
            }

            $obj->$property = $value;
         }

         foreach (QueryBuilderField::get_database_property($cls, 'has_one') as $property => $type) {
            $childStructure = QueryBuilderField::get_value($fields, $property);
            if (empty($childStructure))
               continue;

            $child = $saveChildObject($property, $childStructure);
            if ($child) {
               $propertyID = "{$property}ID";
               $obj->$propertyID = $child->ID;
            }
         }

         $hasMany = QueryBuilderField::get_database_property($cls, 'has_many');
         foreach ($hasMany as $property => $type) {
            $obj->$property()->removeAll();

            foreach (QueryBuilderField::get_value($fields, $property, array()) as $childStructure) {
               if (empty($childStructure))
                  continue;

               $child = $saveChildObject($property, $childStructure);
               if ($child) {
                  $obj->$property()->add($child);
               }
            }
         }
      };

      self::map_ancestry($modelClass, $setProperties);
      $obj->write();
      return $obj;
   }


   /**
    * Return true if Object Representations for the given class
    * should traverse it's has_many relationships
    *
    * @param string $cls
    * @return bool
    */
   public static function traverse_has_many_relationship($cls) {
      $relation = Config::inst()->get($cls, 'has_many');
      return !empty($relation);
   }


   /**
    * Return true if Object Representations for the given class
    * should traverse it's has_one relationships
    *
    * @param string $cls
    * @return bool
    */
   public static function traverse_has_one_relationship($cls) {
      if (is_object($cls))
         $cls = get_class($cls);

      $relation = Config::inst()->get($cls, 'has_one');
      $traverse = Config::inst()->get($cls, 'traverse_has_one');
      return !empty($relation) && !empty($traverse) && $traverse;
   }


   /**
    * Object Constructor. Create a new form field.
    *
    * @param QueryResultsRetriever $resultsRetriever
    */
   public function __construct($name, ViewResultsRetriever $resultsRetriever, $allowExport = true) {
      $this->resultsRetriever = $resultsRetriever;
      $this->allowExport = $allowExport;

      $structure = $this->buildQueryRepr($resultsRetriever);

      parent::__construct($name, false, json_encode($structure));
   }


   /**
    * Create an array data structure to recursively represent the
    * given data object.
    *
    * @param DataObject $obj
    * @return array
    */
   public function buildObjectStructure($obj) {
      $cls = get_class($obj);
      $field = $this;

      $buildChildStructure = function(&$obj, &$name) use ($field) {
         $fnName = "get{$name}Structure";
         if (method_exists($obj, $fnName)) {
            return $obj->$fnName();
         }

         $columnName = "{$name}ID";
         if (isset($obj->$columnName) && $obj->$columnName == 0)
            return null;

         $property = $obj->$name();
         if ($property instanceof SS_List) {
            $output = array();
            foreach ($property as $child)
               $output[] = $field->buildObjectStructure($child);
         } else {
            $output = $field->buildObjectStructure($property);
         }

         return $output;
      };

      $buildStructure = function($cls, $base) use ($obj, $buildChildStructure) {
         $structure = array(
            'type' => $cls,
            'fields' => array());

         // List Database Fields
         foreach(QueryBuilderField::get_database_property($cls, 'db') as $name => $type) {
            $structure['fields'][$name] = $obj->$name;
         }

         // HasOne Relationships
         foreach(QueryBuilderField::get_database_property($cls, 'has_one') as $name => $type) {
            $structure['fields'][$name] = $buildChildStructure($obj, $name);
         }

         // HasMany Relationships
         foreach (QueryBuilderField::get_database_property($cls, 'has_many') as $name => $type) {
            $structure['fields'][$name] = $buildChildStructure($obj, $name);
         }

         return $structure;
      };

      return self::map_ancestry($cls, $buildStructure);
   }


   /**
    * Build a array representation of a ViewResultsRetriever. Includes both a
    * representation of data and a description of the possible data object
    * types that could inhabit it.
    *
    * @param ViewResultsRetriever $query
    * @return array
    */
   private function buildQueryRepr(ViewResultsRetriever $query) {
      $repr = array(
         'data' => $this->buildObjectStructure($query),
         'types' => self::build_core_type_structures(),
      );

      return $repr;
   }


   /**
    * Build a new <input /> tag
    *
    * @return string
    */
   private function getInputTag() {
      $hiddenAttributes = array(
         'type' => 'hidden',
         'class' => 'viewsQueryBuilderRepr',
         'name' => $this->name,
         'value' => $this->value,
         'tabindex' => $this->getAttribute('tabindex')
      );

      return $this->createTag('input', $hiddenAttributes);
   }


   /**
    * Get a Read Only summary of the Query
    *
    * @return string
    */
   private function getReadOnlySummary() {
      $value = $this->resultsRetriever->getReadOnlySummary();

      $attributes = array(
         'id' => $this->id(),
         'class' => 'readonly' . ($this->extraClass() ? $this->extraClass() : '')
      );

      $containerSpan = $this->createTag('span', $attributes, $value);
      $hiddenInput = $this->getInputTag();
      return $containerSpan . "\n" . $hiddenInput;
   }


   /**
    * {@link FormField::performReadonlyTransformation()}
    */
   public function performReadonlyTransformation() {
      $read = clone $this;
      $read->setReadonly(true);
      return $read;
   }


   /**
    * {@link FormField::Field()}
    */
   public function Field($properties = array()) {
      if ($this->readonly) {
         return $this->getReadOnlySummary();
      }

      $html = "<div class='viewsQueryBuilder'></div>";

      if ($this->allowExport) {
         $html .= "<div class='viewsImportExport'></div>";
      }

      $html .= $this->getInputTag();
      return $html;
   }
}
