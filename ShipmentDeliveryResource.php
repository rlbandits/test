<?php

namespace App\Modules\Shipment\Shipment\Services;

use App\Modules\Reason\Reason\Models\Reason;
use Response;
use Schema;
use App\Contracts\FileInterface;
use App\Libraries\StatusHelper;
use App\Libraries\ListHelper;
use App\Libraries\AuthHelper;
use App\Libraries\ResourceHelper;
use App\Libraries\PdfHelper;
use App\Libraries\FileHelper;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use \Carbon\Carbon;
use App\Modules\Shipment\Shipment\Contracts\ShipmentDeliveryResourceInterface;
use App\Modules\Shipment\Shipment\Models\ShipmentDelivery;
use App\Modules\Shipment\Shipment\Models\ShipmentDeliveryItem;
use App\Modules\Shipment\Shipment\Models\Shipment;
use App\Modules\Shipment\Shipment\Models\ShipmentAssignment;
use App\Modules\TruckPersonnel\TruckPersonnel\Models\TruckPersonnel;
use App\Modules\TruckPersonnel\TruckPersonnel\Models\TruckPersonnelTeam;
use App\Modules\TruckPersonnel\TruckPersonnel\Models\TruckTeamHelper;
use App\Modules\Pod\Pod\Models\Pod;
use App\Modules\Store\Store\Models\Store;
use App\Modules\Pgi\Pgi\Models\PgiHdr;
use App\Modules\Pgi\Pgi\Models\PgiDtl;
use App\Modules\ItemMapping\ItemMapping\Models\ItemMapping;
use App\Modules\SafeKeep\SafeKeep\Models\SafeKeepDelivery;

use Ramsey\Uuid\Uuid;
use Excel;

use App\Events\Pod\PodCreated;

class ShipmentDeliveryResource implements ShipmentDeliveryResourceInterface
{

    public $shipmentDelivery;

    public $shippingCoordinator;

    private $file;

    public $user_id;

    protected $module;

    protected $model;

    public function __construct(Request $request, FileInterface $file)
    {
        $this->file = $file;

        $this->user_id = AuthHelper::getUserDataUsingAuthToken($request->header('X-Auth-Token'));
        $this->truck_personnel_id = AuthHelper::getTruckPersonnelDataUsingAuthToken($request->header('X-Auth-Token'));

        /*
         * @var Module name declaration
         */
        $this->module = "Shipment";
        /*
         * @var Model name declaration
        */
        $this->model = "ShipmentDelivery";
    }

    public function getAll()
    {
        $this->shipmentDelivery = ShipmentDelivery::all();
        return $this->shipmentDelivery;
    }

    /**
     * List of Shipment Assignment for a team
     * @param Request $request
     * @return array
     */
    public function listShipmentDeliveryPerTeam($page_size, $page, $sorting = [], $relationship = [], $where = [])
    {
        //check if shipment has an SK
        $check_mseries_id = ShipmentAssignment::where($where[0], "=", $where[1])
            ->where('mseries_id', '!=', null)
            ->get();

        $shipmentDelivery = ShipmentDelivery::with($relationship);

        if (count($where)) {
            $shipmentDelivery = $shipmentDelivery->whereHas('shipmentAssignment', function ($query) use ($where) {
                $query->where($where[0], "=", $where[1]);
                $query->whereNotIn('delivery_status_id', [2, 3, 5]);
            });
        }

        //if shipment has mseries
        $delivery_numbers = [];
        if ($check_mseries_id) {
            foreach ($check_mseries_id as $key => $val) {
                //get delivery number under mseries_id
                $sk_delivery_number = SafeKeepDelivery::where('mseries_id', '=', $val['mseries_id'])->get();
                $delivery_numbers[] = $sk_delivery_number[$key]['delivery_number'];
            }
            if ($delivery_numbers) {
                $shipmentDelivery = $shipmentDelivery->whereIn('delivery_number', $delivery_numbers);
            }
        }

        if (count($sorting)) {
            $shipmentDelivery = $shipmentDelivery->orderBy($sorting['field'], $sorting['sort_order']);
        }

        // Check for valid pagination
        $pagination = ListHelper::validatePagination($page_size, $page);

        // Prepare pagination
        $shipmentDelivery = ($pagination == true) ? $shipmentDelivery->paginate($page_size) : $shipmentDelivery->get();

        // Check model resource response
        if (count($shipmentDelivery) > 0) {
            return $response = [
                'code' => '200',
                'status' => "SC001",
                'data' => $shipmentDelivery->toArray(),
            ];
        } else {
            return $response = [
                'code' => '404',
                'status' => "NF002",
                'message' => 'Resource not found.',
            ];
        }
    }

    public function storeShipmentDelivery(Request $request)
    {
        if ($request->get('shipment')) {
            foreach ($request->get('shipment') as $key_shipment => $row_shipment) {
                // get truck personnel data
                $truck_personnel_data = TruckPersonnel::whereTruckPersonnelId($request->get('truck_personnel_team_id'))->first();
                $shipment_data = Shipment::whereShipmentNumber($row_shipment)->first();

                $this->shipmentDelivery = new ShipmentDelivery;
                //set-up create shipment delivery form
                $this->shipmentDelivery->hauler_id = $truck_personnel_data['hauler_id'];
                $this->shipmentDelivery->truck_personnel_team_id = $request->get('truck_personnel_team_id');
                $this->shipmentDelivery->shipment_id = $shipment_data['shipment_id'];
                $this->shipmentDelivery->loading_bay = $request->get('loading_bay');
                $this->shipmentDelivery->awarding_trip_time = Carbon::now()->toDateTimeString();
                $this->shipmentDelivery->loading_bay_assignment_time = $request->get('loading_bay') ? Carbon::now()->toDateTimeString() : NULL;
                $this->shipmentDelivery->created_by = $this->user_id;
                $this->shipmentDelivery->created_at = Carbon::now()->toDateTimeString();
                $this->shipmentDelivery->updated_by = $this->user_id;
                $this->shipmentDelivery->updated_at = Carbon::now()->toDateTimeString();

                if (!$this->shipmentDelivery->save()) {
                    return StatusHelper::getErrorResponseStatus("Error saving shipment delivery : [" . $row_shipment . "]");
                }
            }
            return StatusHelper::getCreatedResponseStatus("Successfully assigned shipments to team");
        }
    }

    public function showShipmentDelivery($shipmentDelivery_uuid = "")
    {
        $this->shipmentDelivery = ShipmentDelivery::where('shipment_delivery_uuid', '=', $shipmentDelivery_uuid)->first();
        Log::info("SHIPMENT DELIVERY: " . json_encode($this->shipmentDelivery));
        if ($this->shipmentDelivery) {
            return StatusHelper::getSuccessResponseStatus($this->shipmentDelivery->toArray());
        } else {
            return StatusHelper::getNotFoundResponseStatus();
        }
    }

    public function updateShipmentDelivery(Request $request)
    {
        Log::info(json_encode($request));
        Log::info(json_encode($request->request));
        //Update delivery status
        $delivery_status_id = $request->get('delivery_status_id');
        if (!empty($request->get('store'))) {
            $store = $request->get('store');
            Log::info("Request of Store : " . json_encode($request->get('store')));
            foreach ($store as $key => $arr_value) {
                $this->shipmentDelivery = ShipmentDelivery::whereShipmentDeliveryUuid($arr_value['shipment_delivery_uuid'])->first();
                if ($this->shipmentDelivery) {
                    //set-up update shipment delivery form
                    $this->shipmentDelivery->delivery_status_id = $delivery_status_id;
                    $this->shipmentDelivery->delivery_start_time = $arr_value['delivery_start_time'];
                    $this->shipmentDelivery->delivery_end_time = $arr_value['delivery_end_time'];
                    $this->shipmentDelivery->updated_by = $this->truck_personnel_id;
                    $this->shipmentDelivery->updated_at = Carbon::now()->toDateTimeString();

                    if ($this->shipmentDelivery->save()) {
                        $update = $this->shipmentDelivery;
                    }
                }
            }

            if ($update) {
                return StatusHelper::getSuccessResponseStatus("Shipment delivery successfully updated");
            } else {
                return StatusHelper::getNotFoundResponseStatus("Shipment delivery not found", 200);
            }

        } else {
            return StatusHelper::getNotFoundResponseStatus("Shipment delivery not found", 200);
        }
    }

    public function updateShipmentPostDelivery(Request $request)
    {

        Log::info("RECEIVED: " . json_encode($requestall()));
        // tag store close deliveries->
        if (!empty($request->get('StoreClose'))) {
            $update_store_closed = ResourceHelper::batchUpdateResource($request, $this->module, $this->model, 'shipment_delivery_uuid', 'delivery_status_id', '=', $request->get('StoreClose'));
            Log::info("return for store closed : " . $update_store_closed['message']);

        } else {
            $update_store_closed['code'] = '200';
        }

        // tag completed deliveries
        if (!empty($request->get('Delivered'))) {
            Log::info("Request of Delivered : " . json_encode($request->get('Delivered')));
            // update shipment_delivery
            $update_shipment_delivery_end_time = ResourceHelper::batchUpdateWithDetailResource($request, $this->module, $this->model, "ShipmentDeliveryItem", 'shipment_delivery_uuid', 'delivery_end_time', '=', $request->get('Delivered'), "items", "item_code", 0, "seq_num");

            Log::info("return for updating delivery end time: " . json_encode($update_shipment_delivery_end_time) . " - " . $update_shipment_delivery_end_time['message']);

            $update_store_personnel_name = ResourceHelper::batchUpdateWithDetailResource($request, $this->module, $this->model, "ShipmentDeliveryItem", 'shipment_delivery_uuid', 'store_personnel_name', '=', $request->get('Delivered'), "items", "item_code", 0, "seq_num");

            Log::info("return for updating store personnel name: " . json_encode($update_store_personnel_name) . " - " . $update_store_personnel_name['message']);

            $update_shipment_delivery_status = ResourceHelper::batchUpdateWithDetailResource($request, $this->module, $this->model, "ShipmentDeliveryItem", 'shipment_delivery_uuid', 'delivery_status_id', '=', $request->get('Delivered'), "items", "item_code", 0, "seq_num");

            Log::info("return for delivered items : " . json_encode($update_shipment_delivery_status) . " - " . $update_shipment_delivery_status['message']);
            Log::info("update return: " . json_encode($update_shipment_delivery_status));

            if ($update_shipment_delivery_status['code'] == '200') { // == '200'
                $delivery = $request->get('Delivered');

                foreach ($delivery as $key => $arr_value) {
                    //create POD for delivered 
                    $this->shipmentDelivery = ShipmentDelivery::whereShipmentDeliveryUuid($arr_value['shipment_delivery_uuid'])->first();
                    $this->shipmentDeliveryItem = ShipmentDeliveryItem::whereShipmentDeliveryId($this->shipmentDelivery->shipment_delivery_id)->get();
                    $this->shipmentAssignment = ShipmentAssignment::where('shipment_id', '=', $this->shipmentDelivery->shipment_id)
                        ->where('dispatch_time', '<>', NULL)
                        ->where('is_active', '=', 1)
                        ->first();
                    $this->truckPersonnelTeam = TruckPersonnelTeam::whereTruckPersonnelTeamId($this->shipmentAssignment->truck_personnel_team_id)->first();
                    $this->truckPersonnel = TruckPersonnel::whereTruckPersonnelId($this->truckPersonnelTeam->truck_personnel_id)->first();
                    $this->truckTeamHelper = TruckTeamHelper::whereTruckPersonnelTeamId($this->shipmentAssignment->truck_personnel_team_id)->get();

                    $this->pgi_hdr = PgiHdr::wherePgiHdrId($this->shipmentDelivery->pgi_hdr_id)->first();
                    $this->store = Store::whereStoreId($this->shipmentDelivery->store_id)->first();
                    // $pod_count = 10;
                    $row_count = 0;
                    Log::info("PGI HDR Data : " . json_encode($this->pgi_hdr));

                    //check if delivery number is in SK
                    $this->safekeepDelivery = SafeKeepDelivery::whereDeliveryNumber($this->shipmentDelivery->delivery_number)->first();
                    if ($this->safekeepDelivery) {
                        $sk = SafeKeepDelivery::whereSafekeepDeliveryId($this->shipmentDelivery->safekeep_delivery_id)
                            ->update(['delivery_status_id' => 3,
                                'delivered_date' => Carbon::now()->toDateTimeString(),
                                'is_assigned' => 1]);
                        Log::info('Delivery is Sk :' . json_encode($sk));
                    }
                    foreach (config('app.db_pod_hdr_fields') as $index => $field) {

                        if ($field == 'HDR') {
                            $value = 'HDR';
                        } elseif ($field == 'DELV_NO') {
                            $value = $this->shipmentDelivery->delivery_number;
                        } elseif ($field == 'SLORD_NO') {
                            $value = ($this->pgi_hdr) ? $this->pgi_hdr->sales_order_number : '';
                        } elseif ($field == 'SOLD_TO') {
                            $value = $this->pgi_hdr->sold_to_code;
                        } elseif ($field == 'SHIP_TO') {
                            $value = $this->shipmentDelivery->selecta_code_id;
                        } elseif ($field == 'PODAT') {
                            $value = ($this->pgi_hdr) ? date('Ymd', strtotime($this->pgi_hdr->po_date)) : '';
                        } elseif ($field == 'PO_NUMBER') {
                            $value = ($this->pgi_hdr) ? $this->pgi_hdr->mother_order_number : '';
                        } else {
                            $value = '';
                        }
                        $hdata[] = $value;
                    }

                    $csv_data[] = $hdata;

                    foreach ($this->shipmentDeliveryItem as $shipment => $val) {
                        /*if($val['seq_num'] != NULL && !empty($val['seq_num']) && !is_null($val['seq_num'])){

                        } else {
                          $this->pgi_dtl = PgiDtl::where('pgi_hdr_id', '=', $this->pgi_hdr->pgi_hdr_id)
                                          ->where('selecta_item_code', '=', $val['item_code'])
                                          ->get();
                        }*/
                        /*** CODE HERE ***/
                        // $this->pgi_dtl = PgiDtl::groupBy('selecta_item_code')
                        //                 ->selectRaw('*, sum(dispatch_qty) as dispatch_qty_sum')
                        //                 ->where('pgi_hdr_id', $this->pgi_hdr->pgi_hdr_id)
                        //                 ->where('selecta_item_code', '=', $val['item_code'])
                        //                 ->first();
                        $this->pgi_dtl = PgiDtl::where('pgi_hdr_id', '=', $this->pgi_hdr->pgi_hdr_id)
                            ->where([
                                ['selecta_item_code', '=', $val['item_code']],
                                ['seq_num', '=', $val['seq_num']],
                            ])
                            ->first();


                        Log::info("raw query : " . json_encode($this->pgi_dtl) . " " . $this->pgi_hdr->pgi_hdr_id . " " . $val['item_code'] . " " . $val['seq_num']);
                        Log::info("QUANTITY : " . $this->pgi_dtl->dispatch_qty . " - " . $val['quantity']);
                        Log::info("QUANTITY RECEIVED: " . $val['quantity_received']);

                        foreach (config('app.db_pod_dtl_fields') as $index => $field) {

                            if ($field == 'DTL') {
                                $value = 'DTL';
                            } elseif ($field == 'POSNR') {
                                $value = $this->pgi_dtl->line_number;//$val['seq_num'];//$pod_count;
                            } elseif ($field == 'MTNR') {
                                $value = $val['item_code'];
                            } elseif ($field == 'LFIMG') {
                                // $value = $val['quantity'];
                                $value = ($this->pgi_dtl->dispatch_qty) ? $this->pgi_dtl->dispatch_qty : '0';
                            } elseif ($field == 'PODMG') {
                                $value = ($val['quantity_received']) ? $val['quantity_received'] : '0';
                            } elseif ($field == 'VRKME') {
                                $value = ($this->pgi_dtl) ? $this->pgi_dtl->UOM : '';
                                // $value = ($this->pgi_dtl) ? $this->pgi_dtl->UOM : '';
                            } elseif ($field == 'RCODE') {
//                              $reason_code = ResourceHelper::getResourceId($request, 'Reason' , 'Reason' , 'reason_uuid', $arr_sk['reason_uuid']);
                                if ($val->reason_id) {
                                    $reason = Reason::find($val->reason_id);
                                    $value = $reason->reason_code;
                                } else {
                                    $value = '';
                                }
                            } else {
                                $value = '';
                            }
                            $ddata[] = $value;
                        }
                        //removing parent on pod.csv
                        if (!empty($val->hipos)) {
                            $csv_data[] = $ddata;
                        } else {
                            if ($this->shipmentDeliveryItem->where('hipos', $val->line_number)->count() == 0) {
                                $csv_data[] = $ddata;
                            }
                        }
                        // $pod_count += 10;
                        $row_count++;
                        $ddata = [];


                    }
                }

                $holder = $request->get('Delivered');
                $delivery_uuid = $holder[0]['shipment_delivery_uuid'];
                Log::info("DELIVERY UUID: ".$delivery_uuid);

                //create POD for delivered
                $this->shipmentDelivery = ShipmentDelivery::whereShipmentDeliveryUuid($delivery_uuid)->first();
                try {

                    $filename = 'POD_' . $this->shipmentDelivery->selecta_code_id . '_' . date("Ymdhis", strtotime($this->shipmentDelivery->delivery_end_time));
                    Log::info("POD Filename : " . $filename);

                    Log::info("parse data : " . json_encode($ddata));
                    // Log::info("POD Raw Data : ".$csv_data);

                    /* POD csv - try
                            $checkPOD = POD::where('pod_csv_filename', $filename. '.csv')->exists();
                    if($checkPOD) {
                       POD::where('pod_csv_filename', $filename. '.csv')->delete();
                               unlink(public_path().'/uploads/csv/pod'.$filename.'.csv');
                    }*/

                    $file_path = Excel::create($filename, function ($excel) use ($csv_data) {
                        $excel->sheet('POD', function ($sheet) use ($csv_data) {
                            $sheet->fromArray($csv_data, null, 'A1', false, false);
                        });
                    })->store('csv', public_path() . '/uploads/csv/pod', true);

                    //save POD to DB
                    $uuid1 = Uuid::uuid1();
                    $this->pod = new Pod;
                    $this->pod->pod_uuid = $uuid1->toString();
                    $this->pod->store_id = $this->shipmentDelivery->store_id;
                    $this->pod->shipment_delivery_id = $this->shipmentDelivery->shipment_delivery_id;
                    $this->pod->pod_csv_filename = $filename . '.csv';
                    $this->pod->pod_pdf_filename = '';
                    $this->pod->document_number = 0;
                    $this->pod->document_id = 0;
                    $this->pod->synched_status = 0;
                    $this->pod->is_failed = 0;
                    $this->pod->upload_date = Carbon::now()->toDateTimeString();
                    $this->pod->created_at = Carbon::now()->toDateTimeString();
                    $this->pod->updated_at = Carbon::now()->toDateTimeString();

                    if ($this->pod->save()) {
                        Log::info("POD Save to DB : " . $filename . '.csv');
                        $file_type = 'POD';
                        $sftp_upload = FileHelper::sftpUploadFile($file_path['full'], $file_type, $this->pod->pod_csv_filename);
                        if ($sftp_upload) {
                            $this->update_pod = Pod::wherePodUuid($this->pod->pod_uuid)->first();
                            if ($this->update_pod) {
                                $this->update_pod->synched_status = 1;
                                $this->update_pod->is_failed = 0;
                                $this->update_pod->updated_at = Carbon::now()->toDateTimeString();
                                $this->update_pod->save();
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Nothing to do
                    Log::info("POD Not saved on DB");
                }
                
            }

        } else {
            $update_shipment_delivery_status['code'] = '200';
        }
        // insert deliveries as SK
        if (!empty($request->get('SK'))) {

            // update shipment delivery status to SK
            Log::info("Request of SK : " . json_encode($request->get('SK')));
            Log::info("SERVER RECEIVED: " . print_r($request->all(), true));

            $update_store_personnel_name = ResourceHelper::batchUpdateResource($request, $this->module, $this->model, 'shipment_delivery_uuid', 'store_personnel_name', '=', $request->get('SK'));
            Log::info("return for updating store personnel name: " . $update_store_personnel_name['message']);
            $update_shipment_delivery = ResourceHelper::batchUpdateResource($request, $this->module, $this->model, 'shipment_delivery_uuid', 'delivery_status_id', '=', $request->get('SK'));
            Log::info("Return for sk : " . $update_shipment_delivery['message']);
            if ($update_shipment_delivery['code'] == '200') {
                // create SK delivery
                foreach ($request->get('SK') as $key_sk => $arr_sk) {

                    //get store id 
                    $store_id = ResourceHelper::getResourceId($request, 'Store', 'Store', 'store_uuid', $arr_sk['store_uuid']);
                    Log::info("Store id : " . $store_id);
                    //get shipment_delivery_id
                    $shipment_delivery_id = ResourceHelper::getResourceId($request, $this->module, $this->model, 'shipment_delivery_uuid', $arr_sk['shipment_delivery_uuid']);
                    Log::info("Shipment Delivery id : " . $shipment_delivery_id);
                    $reason_id = ResourceHelper::getResourceId($request, 'Reason', 'Reason', 'reason_uuid', $arr_sk['reason_uuid']);
                    Log::info("Reason id : " . $reason_id);
                    $shipment_id = ResourceHelper::getResourceId($request, 'Shipment', 'Shipment', 'shipment_uuid', $arr_sk['shipment_uuid']);
                    Log::info("Original shipment id : " . $shipment_id);
                    $arr_sk['safekeep_delivery_uuid'] = $uuid1 = Uuid::uuid1();
                    $arr_sk['store_id'] = $store_id;
                    $arr_sk['original_shipment_id'] = $shipment_id;//$shipment_delivery_id;
                    $arr_sk['shipment_delivery_id'] = $shipment_delivery_id;
                    $arr_sk['reason_id'] = $reason_id;
                    $arr_sk['created_by'] = $this->truck_personnel_id;
                    $save_sk_delivery = ResourceHelper::storeResource($request, 'SafeKeep', 'SafeKeepDelivery', $arr_sk, 0, 0, $this->truck_personnel_id);
                    Log::info("SK Delivery: " . json_encode($save_sk_delivery));

                }
            } else {
                $save_sk_delivery = 0;
            }

        } else {
            $save_sk_delivery['code'] = '201';
        }


        if (($update_store_closed['code'] !== '200') || ($update_shipment_delivery_status['code'] !== '200') || ($save_sk_delivery['code'] !== '201' || $save_sk_delivery == 0)) {
            $response = [
                "StoreClose" => (($update_store_closed['code'] !== '200') ? 1 : 0),
                "Delivered" => (($update_shipment_delivery_status['code'] !== '200') ? 1 : 0),
                "SK" => (($save_sk_delivery['code'] !== '201' || $save_sk_delivery == 0) ? 1 : 0),

            ];
        } else {
            $response = [];
        }
        if (!empty($response)) {
            Log::info("SENT TO APP: NOTFOUNDRESPONSESTATUS");
            Log::info("RAW SENT: " . StatusHelper::getNotFoundResponseStatus($response, 200));
            return StatusHelper::getNotFoundResponseStatus($response, 200);
        } else {
            //create POD for this
            Log::info("SENT TO APP: SUCCESS");
            Log::info("RAW SENT: " . StatusHelper::getSuccessResponseStatus("Successfully saved post delivery items."));
            return StatusHelper::getSuccessResponseStatus("Successfully saved post delivery items.");
        }
    }

    public function uploadDeliveryReasonPhoto(Request $request)
    {

        //upload photo
        $prefix = $request->get('prefix') . '_' . $request->get('shipment_delivery_uuid');
        $upload_response = $this->file->upload($request, "photo", "delivery/photo", $prefix);
        Log::info("UPLOAD RESPONSE : " . $prefix);
        //update delivery info with uploaded reason photo
        $this->shipmentDelivery = ShipmentDelivery::whereShipmentDeliveryUuid($request->get('shipment_delivery_uuid'))->first();
        if ($this->shipmentDelivery) {
            //set-up update shipment delivery form
            if ($request->get('photo-type') == 1) // if photo-type = 1, photo is for store_closed_image_filename
                $this->shipmentDelivery->store_closed_image_filename = $upload_response['data']['file_name'];
            elseif ($request->get('photo-type') == 2) // if photo-type = 2, photo is for store_personnel_image_filename
                $this->shipmentDelivery->store_personnel_image_filename = $upload_response['data']['file_name'];
            elseif ($request->get('photo-type') == 3) // if photo-type = 3, photo is for store_personnel_signature_filename
                $this->shipmentDelivery->store_personnel_signature_filename = $upload_response['data']['file_name'];
            elseif ($request->get('photo-type') == 4) // if photo-type = 4, photo is for item
                $this->shipmentDelivery->sk_image_filename = $upload_response['data']['file_name'];

            $this->shipmentDelivery->updated_by = $this->truck_personnel_id;
            $this->shipmentDelivery->updated_at = Carbon::now()->toDateTimeString();
            if ($this->shipmentDelivery->save()) {
                Log::info("Shipment Delivery : " . json_encode($this->shipmentDelivery));
                return $response = [
                    'code' => '201',
                    'status' => 'SC001',
                    'data' => 'Successful saving of images.',
                ];
            } else {
                return $response = [
                    'code' => '400',
                    'status' => 'ER000',
                    'message' => "Error updating shipment delivery",
                ];
                Log::info("Shipment Delivery Error : " . json_encode($response));
            }
        } else {
            return $response = [
                'code' => '404',
                'status' => 'NF002',
                'message' => "Shipment delivery not found",
            ];
        }
    }

    public function updateShipmentEODReturns(Request $request)
    {
        Log::info('EOD returns :' . json_encode($request->get('Returns')));
        //$reason_id = ResourceHelper::getResourceId($request, 'Reason' , 'Reason' , 'reason_uuid', $request->get('reason_uuid'));
        //Log::info("Reason id : ".$reason_id);
        //$request->reason_id = $reason_id;

        // check for multiple batch rejection
        // $multibatch_request = self::checkMultiplebatchRejection($request->get('Returns'));

        $update_shipment_delivery_item = ResourceHelper::batchUpdateWithDetailResource($request, $this->module, $this->model, "ShipmentDeliveryItem", 'shipment_delivery_uuid', '', '=', $request->get('Returns'), "items", "item_code", 0, "seq_num");
        // $update_shipment_delivery_item = ResourceHelper::batchUpdateWithDetailResource($request, $this->module, $this->model, "ShipmentDeliveryItem", 'shipment_delivery_uuid', '', '=', $multibatch_request ,"items","item_code",0,"seq_num");

        if ($update_shipment_delivery_item['code'] == '200') {
            return StatusHelper::getSuccessResponseStatus("End of day returns successfully saved.");
        } else {
            return StatusHelper::getNotFoundResponseStatus("Error saving End of day returns", 200);
        }
    }

    /* 
    * Added by luis 4242019 multibatch rejection
    * Desc: Check for the multibatch using item_code in the json returned and separate if we found one 
    */
    public function checkMultiplebatchRejection($original_request)
    {
        // $multibatch_request = $original_request;
        $items_array = [];

        // get the shipment_delivery_id using shipment_delivery_uuid
        $shipment_delivery = ShipmentDelivery::where('shipment_uuid', '=', $original_request['shipment_delivery_uuid'])->first();

        $shipment_delivery_items = [];
        $sdi = $shipment_delivery->shipmentDeliveryItem;
        // arrange by shipment_delivery_item_id
        foreach ($sdi as $sk => $sv)
            $shipment_delivery_items[$sv->shipment_delivery_item_id] = $sv;


        // create array with identification for item multibatch.
        $item_multibatch_count = $item_multibatch_array = $item_total_quantity = [];
        foreach ($shipment_delivery_items as $k => $v) {
            $item_multibatch_count[$v->item_code] = (isset($item_multibatch_count[$v->item_code])) ? $item_multibatch_count[$v->item_code]++ : 1;
            $item_total_quantity[$v->item_code] = (isset($item_total_quantity[$v->item_code])) ? $item_total_quantity[$v->item_code] + $v->quantity : $v->quantity;
            $item_multibatch_array[$v->item_code][] = $v;
        }

        $multibatch_created_items = [];
        // check for item with return
        foreach ($original_request['items'] as $key => $arr_value) {
            // check if with reason_uuid, meaning this item is return with reason
            if (isset($arr_value->reason_uuid)) {
                // check if this item is multibatch else do not execute
                if ($item_multibatch_count[$arr_value->item_code] > 1) {
                    $total_quantity_rejected = $arr_value->quantity_rejected;

                    // this looping will add the multibatch items
                    foreach ($item_multibatch_array[$arr_value->item_code] as $ak => $av) {
                        $qty_rejected = 0;
                        // check if this item quantity > total
                        // deduct the total quantity with the item total amount
                        // check if the current item is sufficient with the total quantity rejected
                        if ($total_quantity_rejected >= $av->quantity) {
                            $qty_rejected = $av->quantity;

                            $total_quantity_rejected -= $qty_rejected;
                        } elseif ($total_quantity_rejected < $av->quantity) {
                            $qty_rejected = $total_quantity_rejected;

                            $total_quantity_rejected = 0;
                        }

                        // create the new items
                        $multibatch_created_items[] = [
                            "seq_num" => $av->seq_num,
                            "physical_validation" => $arr_value->physical_validation,
                            "reason_uuid" => $arr_value->reason_uuid,
                            "item_code" => $arr_value->$item_code,
                            "quantity_rejected" => $qty_rejected,
                            "is_returned" => 1,
                            "good_item" => 0,
                            "bad_item" => $qty_rejected,
                            "short_item" => 0,
                        ];
                    }

                    // unset this index
                    unset($original_request->items[$key]);
                }
            }
        }

        // append the newly created index
        $original_request->items[] = json_encode($multibatch_created_items);

        return $original_request;
    }
}
