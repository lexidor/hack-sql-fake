<?hh // strict

namespace Slack\SQLFake;

use namespace HH\Lib\{C, Dict, Str};

/**
 * Join two data sets using a specified join type and join conditions
 */
abstract final class JoinProcessor {

  public static function process(
    AsyncMysqlConnection $conn,
    dataset $left_dataset,
    dataset $right_dataset,
    string $right_table_name,
    JoinType $join_type,
    ?JoinOperator $_ref_type,
    ?Expression $ref_clause,
    ?table_schema $right_schema,
  ): dataset {

    // MySQL supports JOIN (inner), LEFT OUTER JOIN, RIGHT OUTER JOIN, and implicitly CROSS JOIN (which uses commas), NATURAL
    // conditions can be specified with ON <expression> or with USING (<columnlist>)
    // does not support FULL OUTER JOIN

    $out = vec[];

    // filter can stay as a placeholder for NATURAL joins and CROSS joins which don't have explicit filter clauses
    $filter = $ref_clause ?? new PlaceholderExpression();

    switch ($join_type) {
      case JoinType::JOIN:
      case JoinType::STRAIGHT:
        // straight join is just a query planner optimization of INNER JOIN,
        // and it is actually what we are doing here anyway
        foreach ($left_dataset as $row) {
          foreach ($right_dataset as $r) {
            // copy the left row each time to since we don't want to modify it when we addAll
            $left_row = $row;
            $candidate_row = Dict\merge($row, $r);
            if ((bool)$filter->evaluate($candidate_row, $conn)) {
              $out[] = $candidate_row;
            }
          }
        }
        break;
      case JoinType::LEFT:
        // for left outer joins, the null placeholder represents an appropriate number of nulled-out columns
        // for the case where no rows in the right table match the left table,
        // this null placeholder row is merged into the data set for that row
        $null_placeholder = dict[];
        if ($right_schema !== null) {
          foreach ($right_schema['fields'] as $field) {
            $null_placeholder["{$right_table_name}.{$field['name']}"] = null;
          }
        }

        foreach ($left_dataset as $row) {
          $any_match = false;
          foreach ($right_dataset as $r) {
            // copy the left row each time to since we don't want to modify it when we addAll
            $left_row = $row;
            $candidate_row = Dict\merge($left_row, $r);
            if ((bool)$filter->evaluate($candidate_row, $conn)) {
              $out[] = $candidate_row;
              $any_match = true;
            }
          }

          // for a left join, if no rows in the joined table matched filters
          // we need to insert one row in with NULL for each of the target table columns
          if (!$any_match) {
            // if we have schema for the right table, use a null placeholder row with all the fields set to null
            if ($right_schema !== null) {
              $out[] = Dict\merge($row, $null_placeholder);
            } else {
              $out[] = $row;
            }
          }
        }
        break;
      case JoinType::RIGHT:
        // TODO: calculating the null placeholder set here is actually complex,
        // we need to get a list of all columns from the schemas for all previous tables in the join sequence

        $null_placeholder = dict[];
        if ($right_schema !== null) {
          foreach ($right_schema['fields'] as $field) {
            $null_placeholder["{$right_table_name}.{$field['name']}"] = null;
          }
        }

        foreach ($right_dataset as $raw) {
          $any_match = false;
          foreach ($left_dataset as $row) {
            $left_row = $row;
            $candidate_row = Dict\merge($left_row, $raw);
            if ((bool)$filter->evaluate($candidate_row, $conn)) {
              $out[] = $candidate_row;
              $any_match = true;
            }
          }

          if (!$any_match) {
            $out[] = $raw;
            // TODO set null placeholder
          }
        }
        break;
      case JoinType::CROSS:
        foreach ($left_dataset as $row) {
          foreach ($right_dataset as $r) {
            $left_row = $row;
            $out[] = Dict\merge($left_row, $r);
          }
        }
        break;
      case JoinType::NATURAL:
        // unlike other join filters this one has to be built at runtime, using the list of columns that exists between the two tables
        // for each column in the target table, see if there is a matching column in the rest of the data set. if so, make a filter that they must be equal.
        $filter = self::buildNaturalJoinFilter($left_dataset, $right_dataset);

        // now basically just do a regular join
        foreach ($left_dataset as $row) {
          foreach ($right_dataset as $r) {
            $left_row = $row;
            $candidate_row = Dict\merge($left_row, $r);
            if ((bool)$filter->evaluate($candidate_row, $conn)) {
              $out[] = $candidate_row;
            }
          }
        }
        break;
    }

    return $out;
  }


  /**
   * Somewhat similar to USING clause, but we're just looking for all column names that match between the two tables
   */
  protected static function buildNaturalJoinFilter(dataset $left_dataset, dataset $right_dataset): Expression {
    $filter = null;

    $left = C\first($left_dataset);
    $right = C\first($right_dataset);
    if ($left === null || $right === null) {
      throw new SQLFakeParseException("Attempted NATURAL join with no data present");
    }
    foreach ($left as $column => $val) {
      $name = Str\split($column, '.') |> C\lastx($$);
      foreach ($right as $col => $v) {
        $colname = Str\split($col, '.') |> C\lastx($$);
        if ($colname === $name) {
          $filter = self::addJoinFilterExpression($filter, $column, $col);
        }
      }
    }

    // MySQL actually doesn't throw if there's no matching columns, but I think we can take the liberty to assume it's not what you meant to do and throw here
    if ($filter === null) {
      throw new SQLFakeParseException("NATURAL join keyword was used with tables that do not share any column names");
    }

    return $filter;
  }

  /**
   * For building a NATURAL join filter
   */
  protected static function addJoinFilterExpression(
    ?Expression $filter,
    string $left_column,
    string $right_column,
  ): BinaryOperatorExpression {

    $left =
      new ColumnExpression(shape('type' => TokenType::IDENTIFIER, 'value' => $left_column, 'raw' => $left_column));
    $right =
      new ColumnExpression(shape('type' => TokenType::IDENTIFIER, 'value' => $right_column, 'raw' => $right_column));

    // making a binary expression ensuring those two tokens are equal
    $expr = new BinaryOperatorExpression($left, /* $negated */ false, Operator::EQUALS, $right);

    // if this is not the first condition, make an AND that wraps the current and new filter
    if ($filter !== null) {
      $filter = new BinaryOperatorExpression($filter, /* $negated */ false, Operator::AND, $expr);
    } else {
      $filter = $expr;
    }

    return $filter;
  }
}
