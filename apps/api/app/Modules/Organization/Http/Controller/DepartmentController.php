<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Organization\Application\Service\DepartmentService;
use App\Modules\Organization\Http\Request\CreateDepartmentRequest;
use App\Modules\Organization\Http\Request\MoveDepartmentRequest;
use App\Modules\Organization\Http\Request\UpdateDepartmentRequest;
use App\Modules\Organization\Http\Resource\DepartmentResource;
use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;

final class DepartmentController extends ApiController
{
    public function __construct(
        private readonly DepartmentService $departments,
    ) {}

    /**
     * The whole tree in one query.
     *
     * A department list is bounded (tens, not thousands) and is always rendered
     * as a hierarchy, so it is returned whole and ordered by path — which is
     * already depth-first order, for free, because of the materialized path.
     */
    public function index(): ApiResponse
    {
        $departments = DepartmentModel::query()
            ->withCount('teams')
            ->orderBy('path')
            ->get();

        return $this->ok(DepartmentResource::collection($departments));
    }

    public function store(CreateDepartmentRequest $request): ApiResponse
    {
        $department = $this->departments->create(
            name: $request->string('name')->toString(),
            code: $request->string('code')->toString(),
            parentId: $request->input('parent_id'),
        );

        return $this->created(new DepartmentResource($department));
    }

    public function update(UpdateDepartmentRequest $request, DepartmentModel $department): ApiResponse
    {
        $this->authorize('update', $department);

        $department->forceFill($request->validated())->save();

        return $this->ok(new DepartmentResource($department));
    }

    public function move(MoveDepartmentRequest $request, DepartmentModel $department): ApiResponse
    {
        $this->authorize('update', $department);

        $moved = $this->departments->move($department, $request->input('parent_id'));

        return $this->ok(new DepartmentResource($moved));
    }
}
