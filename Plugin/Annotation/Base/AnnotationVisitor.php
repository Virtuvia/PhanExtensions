<?php declare(strict_types=1);

namespace Drenso\PhanExtensions\Visitor\Annotation\Base;

require_once __DIR__ . '/../../../Helper/NamespaceChecker.php';

use Drenso\PhanExtensions\Helper\NamespaceChecker;
use Phan\Language\FQSEN;
use Phan\Language\Type;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use ast\Node;

/**
 * Class AnnotationVisitor
 *
 * {@inheritdoc}
 *
 * @author BobV
 */
abstract class AnnotationVisitor extends PluginAwarePostAnalysisVisitor
{

  const annotation_regex = '/[^"]@(' . Type::simple_type_regex . ')[\(]?/';
  const class_name_resolution_regex = '/(' . Type::simple_type_regex . ')::class/';
  const const_reference_regex = '/(' . Type::simple_type_regex . ')::([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)(\.html\.twig)?/';

  /**
   * Holds the exceptions for a specific framework
   *
   * @var array
   *
   * @suppress PhanReadOnlyProtectedProperty
   */
  protected $exceptions = [];

  /**
   * Visit class
   *
   * @param Node $node
   *
   * @throws \AssertionError
   */
  public function visitClass(Node $node)
  {
    $this->checkDocComment($node);
  }

  /**
   * Visit method
   *
   * @param Node $node
   *
   * @throws \AssertionError
   */
  public function visitMethod(Node $node)
  {
    $this->checkDocComment($node);
  }

  /**
   * Visit property
   *
   * @param Node $node
   *
   * @throws \AssertionError
   */
  public function visitPropElem(Node $node)
  {
    $this->checkDocComment($node);
  }

  /**
   * Retrieves the docblock for the node, and checks for the given annotations
   *
   * @param Node $node
   *
   * @throws \AssertionError
   */
  private function checkDocComment(Node $node)
  {
    // Retrieve the doc block
    /* @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset */
    $docComment = array_key_exists('docComment', $node->children) ? $node->children['docComment'] : NULL;

    // Ignore empty doc blocks
    if ($docComment === NULL || strlen($docComment) == 0) {
      return;
    }

    // Retrieve all annotations from the doc comment
    preg_match_all(self::annotation_regex, $docComment, $matches);
    foreach ($matches[1] as $annotation) {
      // Check for exceptions
      if (in_array($annotation, $this->exceptions)) continue;

      // Annotation should start with upper case letter
      if (!$this->starts_with_upper($annotation)) continue;

      // Check for annotation
      NamespaceChecker::checkVisitor($this, $this->code_base, $this->context, $annotation, 'AnnotationNotImported',
          'The classlike {CLASS} annotation is undeclared (generated by DrensoAnnotation plugin)');
    }

    // Retrieve all class name resolutions from the doc comment
    preg_match_all(self::class_name_resolution_regex, $docComment, $matches);
    foreach ($matches[1] as $classToBeResolved) {
      // Check for exceptions
      if (in_array($classToBeResolved, $this->exceptions)) continue;

      // Check for annotation
      NamespaceChecker::checkVisitor($this, $this->code_base, $this->context, $classToBeResolved, 'ClassNameResolutionNotImported',
          'The classlike {CLASS} used for class name resolution (::class) is undeclared (generated by DrensoAnnotation plugin)');
    }

    // Retrieve all possible constant references from the doc comment
    preg_match_all(self::const_reference_regex, $docComment, $matches);
    foreach ($matches[0] as $key => $fullMatch) {
      // Test if not class constant
      $const = $matches[3][$key];
      if ($const === 'class') {
        continue;
      }

      // Test if not .html.twig
      if (($matches[4][$key] ?? '') === '.html.twig'){
        continue;
      }

      $classToBeResolved = $matches[1][$key];

      // Check for exceptions
      if (in_array($classToBeResolved, $this->exceptions)) continue;

      // It might be fully qualified already, so try that first
      // Note that it does not need to start with a \ for Doctrine
      $fqsClass = FQSEN\FullyQualifiedClassName::fromFullyQualifiedString($classToBeResolved);
      if (!$this->code_base->hasClassWithFQSEN($fqsClass)) {
        if (!$fqsClass = NamespaceChecker::resolveClassFQS($this->context, $classToBeResolved)) {
          // If not resolved, ignore it
          continue;
        }
      }

      if (!$this->code_base->hasClassWithFQSEN($fqsClass)) {
        $this->emit(
            'ConstReferenceClassNotImported',
            'The classlike {CLASS} used in {COMMENT} is undeclared (generated by DrensoAnnotation plugin)',
            [(string)$fqsClass, $fullMatch]
        );
        continue;
      }

      if (!$this->code_base->getClassByFQSEN($fqsClass)->hasConstantWithName($this->code_base, $const)){
        $this->emit(
            'ConstReferenceConstNotFound',
            'The const {CONST} from {COMMENT} is undeclared in classlike {CLASS} (generated by DrensoAnnotation plugin)',
            [$const, $fullMatch, (string)$fqsClass]
        );
      }
    }
  }

  private function starts_with_upper($str)
  {
    $chr = mb_substr($str, 0, 1, "UTF-8");

    return mb_strtolower($chr, "UTF-8") != $chr;
  }
}
