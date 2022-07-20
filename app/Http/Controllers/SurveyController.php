<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSurveyAnswerRequest;
use App\Models\Survey;
use App\Http\Requests\StoreSurveyRequest;
use App\Http\Requests\UpdateSurveyRequest;
use App\Http\Resources\SurveyResource;
use App\Models\SurveyAnswer;
use App\Models\SurveyQuestion;
use App\Models\SurveyQuestionAnswer;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = Auth::user();
        return SurveyResource::collection(Survey::where('user_id', $user->id)->paginate(5));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSurveyRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSurveyRequest $request)
    {
        try {
            $data = $request->validated();

            if(isset($data['image'])){
                $relativePath = $this->saveImage($data['image']);
                $data['image'] = $relativePath;
            }

            $survey = Survey::create($data);

            //Create new questions
            foreach ($data['questions'] as $question) {
                $question['survey_id'] = $survey->id;
                $this->createQuestion($question);
            }
    
            return new SurveyResource($survey);
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function show(Survey $survey)
    {
        try {
            $user = Auth::user();
    
            if($user->id !== $survey->user_id){
                return abort(403, 'Unauthorized action');
            }
    
            return new SurveyResource($survey);
        } catch (Throwable $th) {
            return $th;
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSurveyRequest  $request
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSurveyRequest $request, Survey $survey)
    {
        try {
            $data = $request->validated();

            if(isset($data['image'])){
                $relativePath = $this->saveImage($data['image']);
                $data['image'] = $relativePath;

                if($survey->image){
                    $absolutePath = public_path($survey->image);
                    File::delete($absolutePath);
                }
            }

            $survey->update($data);

            // Get ids as plain array of existing questions
                $existingIds = $survey->questions()->pluck('id')->toArray();
            // Get ids as plain array of new questions
                $newIds = Arr::pluck($data['questions'], 'id');
            // Find questions to delete
                $toDelete = array_diff($existingIds, $newIds);
            // Find questions to add
                $toAdd = array_diff($newIds, $existingIds);
            // Delete questions by $toDelete array
                SurveyQuestion::destroy($toDelete);
            // Create nwe questions
                foreach($data['questions'] as $question){
                    if(in_array($question['id'], $toAdd)){
                        $question['survey_id'] = $survey->id;
                        $this->createQuestion($question);
                    }
                }
            // Update existing questions
                $questionMap = collect($data['questions'])->keyBy('id');
                foreach($survey->questions as $question){
                    if(isset($questionMap[$question->id])){
                        $this->updateQuestion($question, $questionMap[$question->id]);
                    }
                }
    
            return new SurveyResource($survey);
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function destroy(Survey $survey)
    {
        $user = Auth::user();

        if($user->id !== $survey->user_id){
            return abort(403, 'Unauthorized action');
        }

        $survey->delete();

        if($survey->image){
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }

        return response('', 204);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function showForGuest(Survey $survey)
    {
        try {
            return new SurveyResource($survey);
        } catch (Throwable $th) {
            return $th;
        }
    }

    public function storeAnswer(Survey $survey, StoreSurveyAnswerRequest $request)
    {
        try {
            $validated = $request->validated();
            
            $surveyAnswer = SurveyAnswer::create([
                'survey_id' => $survey->id,
                'start_date' => Carbon::now()->format('Y-m-d h:i:s'), //date('Y-m-d H:i:s'),
                'end_date' => Carbon::now()->format('Y-m-d h:i:s') //Carbon::now()->format('Y-m-d h:i:s')
            ]);
            
            //return $surveyAnswer;

            foreach($validated['answers'] as $questionId => $answer){
                $question = SurveyQuestion::where(['id' => $questionId, 'survey_id' => $survey->id])->get();
    
                if(!$question){
                    return response("Invalid question ID: \"$questionId\"", 400);
                }
    
                $data = [
                    'survey_question_id' => $questionId,
                    'survey_answer_id' => $surveyAnswer->id,
                    'answer' => is_array($answer) ? json_encode($answer) : $answer
                ];
    
                SurveyQuestionAnswer::create($data);
            }
        } catch (Throwable $th) {
            return $th;
        }
    }



    private function saveImage($image)
    {
        try{
            if(preg_match('/^data:image\/(\w+);base64,/', $image, $type)){
                $image = substr($image, strpos($image, ',') + 1);
    
                $type = strtolower($type[1]);
    
                if(!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])){
                    throw new Exception('inavalid image type');
                }
    
                $image = str_replace('', '+', $image);
                $image = base64_decode($image);
    
                if($image === false){
                    throw new Exception('based64_decode failed');
                }
            }else{
                throw new Exception('did not match data URI with image data');
            }
    
            $dir = 'images/';
            $file = Str::random() . '.' . $type;
            $absolutePath = public_path($dir);
            $relativePath = $dir . $file;
    
            if(!File::exists($absolutePath)){
                File::makeDirectory($absolutePath, 0755, true);
            }
    
            file_put_contents($relativePath, $image);
    
            return $relativePath;
        }catch(Throwable $e){
            return $e;
        }
    }

    private function createQuestion($data)
    {
        if(is_array($data['data'])){
            $data['data'] = json_encode($data['data']);
        }

        $validator = Validator::make($data, [
            'question' => 'required|string',
            'type' => ['required', Rule::in([
                Survey::TYPE_TEXT,
                Survey::TYPE_TEXTAREA,
                Survey::TYPE_SELECT,
                Survey::TYPE_RADIO,
                Survey::TYPE_CHECKBOX,
            ])],
            'description' => 'nullable|string',
            'data' => 'present',
            'survey_id' => 'exists:App\Models\Survey,id'
        ]);

        return SurveyQuestion::create($validator->validated());
    }

    private function updateQuestion($question, $data)
    {
        if(is_array($data['data'])){
            $data['data'] = json_encode($data['data']);
        }

        $validator = Validator::make($data, [
            'id' => 'exists:App\Models\SurveyQuestion,id',
            'question' => 'required|string',
            'type' => ['required', Rule::in([
                Survey::TYPE_TEXT,
                Survey::TYPE_TEXTAREA,
                Survey::TYPE_SELECT,
                Survey::TYPE_RADIO,
                Survey::TYPE_CHECKBOX,
            ])],
            'description' => 'nullable|string',
            'data' => 'present',
            'survey_id' => 'exists:App\Models\Survey,id'
        ]);

        return $question->update($validator->validated());
    }
}
