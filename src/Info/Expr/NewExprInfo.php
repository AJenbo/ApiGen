<?php declare(strict_types = 1);

namespace ApiGenX\Info\Expr;

use ApiGenX\Info\ExprInfo;
use ApiGenX\Info\ClassLikeReferenceInfo;


final class NewExprInfo implements ExprInfo
{
	/**
	 * @param ArgExprInfo[] $args
	 */
	public function __construct(
		public ClassLikeReferenceInfo $classLike,
		public array $args,
	) {
	}
}
