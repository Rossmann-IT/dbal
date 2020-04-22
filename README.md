# Doctrine DBAL

This Fork adds the missing capabilities for Oracle 12.x
* Function Based Indexes (with expressions on any index column)
* IDENTITY columns instead of sequences/triggers
* Column-level collations
* Huge performance improvement on schema introspection

### How to use Oracle-specific features:

##### IDENTITY columns

Use `'autoincrement' => true` in the options of a column to define a column as `GENERATED BY DEFAULT ON NULL AS IDENTITY`

##### Column-level collations

Add `'platformOptions' => ['collation' => 'XGERMAN_CI']` to the options array

##### Function Based Indexes

Other DBMS either do not support function based indexes at all or support only one WHERE-condition for the whole index,
that's why Doctrine does not support a convenient way to manage several expressions in one index.
With this fork you can define as many expressions as different column names are mentioned in the expressions.
You have to write the expression exactly in the same way as Oracle stores it in `user_ind_expressions.column_expression`,
except that trailing spaces and double spaces are removed.
Doctrine requires that the expressions are mapped to existing columns, use the column names in the order in which they appear in the expressions. 
It is a known limitation that not every possible combination of expressions can be mapped this way.

Example:
````php
$table->addUniqueIndex(
    ['COLUMN_A', 'COLUMN_B'],
    'UX_INDEX_NAME',
    ['where' => [
        'COLUMN_A' => 'CASE WHEN ("COLUMN_A"=0 AND "COLUMN_B"=1) THEN "COLUMN_C" END',
        'COLUMN_B' => 'CASE WHEN ("COLUMN_A"=0 AND "COLUMN_B"=1) THEN "COLUMN_D" END',
    ]]);
````


## Original README from doctrine/dbal:

| [Master][Master] | [2.9][2.9] | [Develop][develop] 
|:----------------:|:----------:|:------------------:|
| [![Build status][Master image]][Master] | [![Build status][2.9 image]][2.9] | [![Build status][develop image]][develop] |
| [![Build Status][ContinuousPHP image]][ContinuousPHP] | [![Build Status][ContinuousPHP 2.9 image]][ContinuousPHP] | [![Build Status][ContinuousPHP develop image]][ContinuousPHP] |
| [![Code Coverage][Coverage image]][Scrutinizer Master] | [![Code Coverage][Coverage 2.9 image]][Scrutinizer 2.9] | [![Code Coverage][Coverage develop image]][Scrutinizer develop] |
| [![Code Quality][Quality image]][Scrutinizer Master] | [![Code Quality][Quality 2.9 image]][Scrutinizer 2.9] | [![Code Quality][Quality develop image]][Scrutinizer develop] |
| [![AppVeyor][AppVeyor master image]][AppVeyor master] | [![AppVeyor][AppVeyor 2.9 image]][AppVeyor 2.9] | [![AppVeyor][AppVeyor develop image]][AppVeyor develop] |

Powerful database abstraction layer with many features for database schema introspection, schema management and PDO abstraction.

## More resources:

* [Website](http://www.doctrine-project.org/projects/dbal.html)
* [Documentation](http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/)
* [Issue Tracker](https://github.com/doctrine/dbal/issues)

  [Master image]: https://img.shields.io/travis/doctrine/dbal/master.svg?style=flat-square
  [Coverage image]: https://img.shields.io/scrutinizer/coverage/g/doctrine/dbal/master.svg?style=flat-square
  [Quality image]: https://img.shields.io/scrutinizer/g/doctrine/dbal/master.svg?style=flat-square
  [ContinuousPHP image]: https://img.shields.io/continuousphp/git-hub/doctrine/dbal/master.svg?style=flat-square
  [Master]: https://travis-ci.org/doctrine/dbal
  [Scrutinizer Master]: https://scrutinizer-ci.com/g/doctrine/dbal/
  [AppVeyor master]: https://ci.appveyor.com/project/doctrine/dbal/branch/master
  [AppVeyor master image]: https://ci.appveyor.com/api/projects/status/i88kitq8qpbm0vie/branch/master?svg=true
  [ContinuousPHP]: https://continuousphp.com/git-hub/doctrine/dbal

  [2.9 image]: https://img.shields.io/travis/doctrine/dbal/2.9.svg?style=flat-square
  [Coverage 2.9 image]: https://img.shields.io/scrutinizer/coverage/g/doctrine/dbal/2.9.svg?style=flat-square
  [Quality 2.9 image]: https://img.shields.io/scrutinizer/g/doctrine/dbal/2.9.svg?style=flat-square
  [ContinuousPHP 2.9 image]: https://img.shields.io/continuousphp/git-hub/doctrine/dbal/2.9.svg?style=flat-square
  [2.9]: https://github.com/doctrine/dbal/tree/2.9
  [Scrutinizer 2.9]: https://scrutinizer-ci.com/g/doctrine/dbal/?branch=2.9
  [AppVeyor 2.9]: https://ci.appveyor.com/project/doctrine/dbal/branch/2.9
  [AppVeyor 2.9 image]: https://ci.appveyor.com/api/projects/status/i88kitq8qpbm0vie/branch/2.9?svg=true

  [develop]: https://github.com/doctrine/dbal/tree/develop
  [develop image]: https://img.shields.io/travis/doctrine/dbal/develop.svg?style=flat-square
  [Coverage develop image]: https://img.shields.io/scrutinizer/coverage/g/doctrine/dbal/develop.svg?style=flat-square
  [Quality develop image]: https://img.shields.io/scrutinizer/g/doctrine/dbal/develop.svg?style=flat-square
  [ContinuousPHP develop image]: https://img.shields.io/continuousphp/git-hub/doctrine/dbal/develop.svg?style=flat-square
  [develop]: https://github.com/doctrine/dbal/tree/develop
  [Scrutinizer develop]: https://scrutinizer-ci.com/g/doctrine/dbal/?branch=develop
  [AppVeyor develop]: https://ci.appveyor.com/project/doctrine/dbal/branch/develop
  [AppVeyor develop image]: https://ci.appveyor.com/api/projects/status/i88kitq8qpbm0vie/branch/develop?svg=true
