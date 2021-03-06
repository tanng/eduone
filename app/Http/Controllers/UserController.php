<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\User;
use App\Role;
use App\Branch;
use App\Program;
use App\Repositories\UserRepository;

class UserController extends Controller
{
    /**
     * Available Roles
     * @var array
     */
    protected $roles = [];

    /**
     * Available Branches
     * @var array
     */
    protected $branches = [];

    /**
     * Available Programs
     * @var array
     */
    protected $programs = [];

    protected $user;

    public function __construct(UserRepository $user)
    {
        $this->roles       = Role::orderBy('id', 'desc')->pluck('name', 'id')->toArray();
        
        $this->branches    = Branch::pluck('name', 'id')->toArray();

        $this->programs    = Program::pluck('name', 'id')->toArray();

        $this->user        = $user;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $users = User::search($request->all())
                    ->orderBy('created_at')
                    ->paginate(20); 

        return view('users.index', [
            'users'     => $users,
            'roles'     => $this->roles,
            'branches'  => $this->branches,
            'request'   => $request
        ]);
    }

    public function search(Request $request)
    {
        return User::search($request->all())
                    ->orderBy('created_at', 'DESC')
                    ->get(['id', 'display_name', 'profile_picture'])
                    ->take(20);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        return view('users.create', [
            'roles'     => $this->roles,
            'branches'  => $this->branches,
            'programs'  => $this->programs,
            'request'   => $request
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = array_filter($request->all());
        
        if (! empty($data['branches'])) {
            $branches = $data['branches'];

            foreach($branches as $index => $branch) {
                $branches[$index] = intval($branch);
            }
        }

        if (! empty($data['password']))
            $data['password'] = bcrypt($data['password']);

        try {
            $user = User::create($data);

            if (isset($branches))
                $user->branches()->sync($branches);

            return redirect('users/' . $user->id )
                        ->with('message', 'User was created successfully!');
        } catch (Exception $e) {
            return back()->withInput()->with('message', 'Fooo!');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(User $user, Request $request)
    {
        $user_branches = $user->branches->pluck('id')->toArray();
        $user_programs = $user->programs->pluck('id')->toArray();

        $permissions= config('settings.permissions');
        
        if ( ! isset($request->tab))
            $request->tab = 'account';

        $user_repository = $this->user;
        $user_repository->setUser($user);

        $pass_to_view = [
            'user'          => $user,
            'roles'         => $this->roles,
            'branches'      => $this->branches,
            'user_branches' => $user_branches,
            'user_programs' => $user_programs,
            'permissions'   => $permissions,
            'programs'      => $this->programs,
            'request'       => $request,
            'user_repository' => $user_repository
        ];

        if ($user->isRole([3,4])) {
            $subjects         = \App\Subject::pluck('name', 'id')->toArray();

            $pass_to_view['subjects'] = $subjects;
        }

        if ($user->isTeacher()) {
            
            $teacher_subjects = $user->subjects->pluck('id')->toArray(); 

        }

        if ($user->isStudent()) {

            $user_subjects_pivot = \DB::table('users_subjects')
                                        ->where('user_id', $user->id)
                                        ->get();

            $student_grades = \DB::table('users_grades')
                                    ->where('user_id', $user->id)
                                    ->orderBy('subject_id')
                                    ->orderBy('grade_id')
                                    ->get();

            $student_grades = collect($student_grades)->sortBy('grade_id')->groupBy('subject_id')->toArray();
            

            $pass_to_view['user_subjects_pivot']    = $user_subjects_pivot;
            $pass_to_view['student_grades']         = $student_grades;
        }

        return view('users.update', $pass_to_view);
    }

    public function profile(Request $request)
    {
        // Todo: Get current user id
        $user = User::find(1);

        // Todo: Only show specified part
        return $this->show($user, $request);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(User $user)
    {
        return $this->show($user);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, User $user)
    {
        $data = $request->all();

        $branches = isset($data['branches']) ? $data['branches'] : [];
        $programs = isset($data['programs']) ? $data['programs'] : [];
        
        // $subjects = isset($data['subjects']) ? $data['subjects'] : [];

        if ( ! empty($data['profile_picture'])) {
            $photo      = $request->file('profile_picture');
            $photo_path = $photo->getRealPath();
            $photo_name = $photo->getClientOriginalName();

            $photo->move(
                base_path() . '/public/photos/', $photo_name
            );

            $data['profile_picture'] = $photo_name;
        }

        if ( ! empty($data['family_members']) )
        {
            $users = [];
            $queue = json_decode($data['family_members'], true);

            // Todo: Use better function that foreach
            foreach ($queue as $member) {
                $users[$member['id']] = $member['id'];
            }

            try {
                if ($user->isStudent())
                    $user->parents()->attach($users);
                else
                    $user->childrens()->attach($users);
            } catch (Exception $e) {
                return back()->withMessage('Family member has already added!');
            }
        }

        if (! empty($data['password']))
            $data['password'] = bcrypt($data['password']);

        try {
            $user->update($data);

            $user->branches()->sync($branches);
            
            $user->programs()->sync($programs);

            // $user->subjects()->sync($subjects);

            return back()->with('message', 'User was updated successfully!');

        } catch(Exception $e) {
            return back()->withInput()->with('message', 'Error during updating user. Please try again later.');
        }
    }

    public function removeMember($user, $family_member)
    {
        $user = User::findOrFail($user);

        if ($user->isStudent())
            $user->parents()->detach($family_member);
        else
            $user->childrens()->detach($family_member);

        return back()->withMessage('Family member was removed successfully!');
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        $user->delete();

        return back()->withMessage('User was deleted successfully!');
    }
}
